<?php
/**
 * SubscribersPlugin for phplist.
 *
 * This file is a part of SubscribersPlugin.
 *
 * SubscribersPlugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * SubscribersPlugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * @author    Duncan Cameron
 * @copyright 2011-2016 Duncan Cameron
 * @license   http://www.gnu.org/licenses/gpl.html GNU General Public License, Version 3
 */

/**
 * This class is the controller for the plugin providing the action methods.
 */
use phpList\plugin\Common\DB;
use phpList\plugin\Common\PageURL;
use phpList\plugin\Common\Toolbar;

class SubscribersPlugin_Controller_Action extends CommonPlugin_Controller
{
    const COMMAND_UNCONFIRM = 0;
    const COMMAND_BLACKLIST = 1;
    const COMMAND_DELETE = 2;
    const COMMAND_REMOVE = 3;
    const COMMAND_UNBLACKLIST = 4;

    const HTML_ENABLED = 0;
    const HTML_DISABLED = 1;

    const PLUGIN = 'SubscribersPlugin';
    const TEMPLATE = '/../view/action.tpl.php';
    const TEMPLATE_2 = '/../view/action_2.tpl.php';
    const TEMPLATE_3 = '/../view/action_3.tpl.php';
    const IDENTIFIER = 'Action Subscribers';
    /*
     *  Private variables
     */
    private $dao;
    private $model;
    private $toolbar;

    /**
     * Saves variables into the session then redirects and exits.
     *
     * @param string $redirect the redirect location
     * @param array  $session  variables to be stored in the session
     */
    private function redirectExit($redirect, array $session = array())
    {
        $_SESSION[self::PLUGIN] = $session;
        header('Location: ' . $redirect);
        exit;
    }

    /**
     * Validates that a file has been successfully uploaded.
     *
     * @return string an error message, empty for success
     */
    private function validateFile()
    {
        $error = '';
        $f = $this->model->file;

        if ($f['error'] != 0) {
            $errorText = array(
                1 => $this->i18n->get('upload_error_1'),
                2 => $this->i18n->get('upload_error_2'),
                3 => $this->i18n->get('upload_error_3'),
                4 => $this->i18n->get('upload_error_4'),
                6 => $this->i18n->get('upload_error_6'),
            );
            $error = $errorText[$f['error']];
        } elseif (!preg_match('/csv|text/', $f['type'])) {
            $error = $this->i18n->get('error_extension');
        } elseif ($f['size'] == 0) {
            $error = $this->i18n->get('error_empty', $f['name']);
        }

        return $error;
    }

    /**
     * Applies the command to the set of subscribers.
     *
     * @param array $users  email addresses
     * @param bool  $command The command to be applied
     * @param bool  $listId List id
     * 
     * @return string a message summarising the command and number of affected subscribers
     */
    private function processUsers(array $users, $command, $listId)
    {
        switch ($command) {
            case self::COMMAND_UNCONFIRM:
                $count = 0;

                foreach ($users as $email) {
                    if ($this->dao->unconfirmUser($email)) {
                        ++$count;
                        addUserHistory(
                            $email,
                            self::IDENTIFIER,
                            $this->i18n->get('history_unconfirmed')
                        );
                    }
                }
                $result = $this->i18n->get('result_unconfirmed', $count);
                break;
            case self::COMMAND_BLACKLIST:
                $count = 0;

                foreach ($users as $email) {
                    addUserToBlackList($email, $this->i18n->get('history_blacklisted', self::IDENTIFIER));
                    ++$count;
                }
                $result = $this->i18n->get('result_blacklisted', $count);
                break;
            case self::COMMAND_UNBLACKLIST:
                $count = 0;

                foreach ($users as $email) {
                    $user = $this->dao->userByEmail($email);

                    if ($user['blacklisted']) {
                        unBlackList($user['id']);
                        ++$count;
                    }
                }
                $result = $this->i18n->get('result_unblacklisted', $count);
                break;
            case self::COMMAND_DELETE:
                $dao = $this->dao;
                $deletedCount = 0;
                array_walk(
                    $users,
                    function ($email, $index) use ($dao, &$deletedCount) {
                        if ($row = $dao->userByEmail($email)) {
                            deleteUser($row['id']);
                            ++$deletedCount;
                        }
                    }
                );
                $result = $this->i18n->get('result_deleted', $deletedCount);
                break;
            case self::COMMAND_REMOVE:
                $listName = $this->dao->listName($listId);
                $count = 0;

                foreach ($users as $email) {
                    $this->dao->removeFromList($email, $listId);
                    ++$count;
                    addUserHistory(
                        $email,
                        self::IDENTIFIER,
                        $this->i18n->get('history_removed', $listName)
                    );
                }
                $result = $this->i18n->get('result_removed', $listName, $count);
                break;
        }

        $this->logEvent(sprintf('%s - %s', self::IDENTIFIER, $result));

        return $result;
    }

    /**
     * Extracts email addresses from the uploaded file.
     * Lines without @ are ignored.
     *
     * @return array email addresses
     */
    private function loadUsersFromFile()
    {
        $emails = file($this->model->file['tmp_name'], FILE_SKIP_EMPTY_LINES);
        $emails = array_map('trim', $emails);

        return array_filter(
            $emails,
            function ($item) {
                return (strpos($item, '@') !== false);
            }
        );
    }

    /**
     * Generates the html for a dropdown list of lists owned by the current admin.
     *
     * @param bool $enabled Whether the list should be enabled
     *
     * @return string the html
     */
    private function dropDownList($disabled)
    {
        $lists = iterator_to_array($this->dao->listsForOwner(null));

        return CHtml::dropDownList(
            'listId', $this->model->listId, array_column($lists, 'name', 'id'), array('disabled' => $disabled)
        );
    }

    /**
     * Generates the html for a group of radio buttons.
     *
     * @param bool $enabled Whether the buttons should be enabled
     *
     * @return string the html
     */
    private function radioButtonList($disabled)
    {
        return CHtml::radioButtonList(
            'command',
            $this->model->command,
            array(
                self::COMMAND_UNCONFIRM => $this->i18n->get('Unconfirm'),
                self::COMMAND_BLACKLIST => $this->i18n->get('Blacklist'),
                self::COMMAND_UNBLACKLIST => $this->i18n->get('Unblacklist'),
                self::COMMAND_DELETE => $this->i18n->get('Delete'),
                self::COMMAND_REMOVE => $this->i18n->get('Remove from list'),
            ),
            array('separator' => '<br />', 'disabled' => $disabled)
        );
    }

    /**
     * Exports the set of invalid email addresses.
     */
    protected function actionExportinvalid()
    {
        $fileName = 'invalid_email.txt';
        ob_end_clean();
        Header('Content-type: text/plain');
        Header("Content-disposition:  attachment; filename=$fileName");

        foreach ($_SESSION[self::PLUGIN]['invalid'] as $invalid) {
            echo $invalid['email'], "\n";
        }
        exit;
    }

    /**
     * Validates the email address of each subscriber and displays those that are invalid.
     * If there are none invalid then redirects to the first page.
     */
    protected function actionValidate()
    {
        $invalid = array();

        foreach ($this->dao->allUsers() as $row) {
            if (!is_email($row['email'])) {
                $invalid[] = $row;
            }
        }

        if (count($invalid) == 0) {
            $this->redirectExit(
                new PageURL(),
                array('result' => $this->i18n->get('All subscribers have a valid email address'))
            );
        }
        $_SESSION[self::PLUGIN]['invalid'] = $invalid;

        $populator = new SubscribersPlugin_InvalidPopulator($this->i18n, $invalid);
        $listing = new CommonPlugin_Listing($this, $populator);
        $this->toolbar->addExportButton(array('action' => 'exportinvalid'));
        $this->toolbar->addHelpButton('help');
        $cancel = new CommonPlugin_PageLink(new PageURL(null), 'Cancel', array('class' => 'button'));
        $params = array(
            'toolbar' => $this->toolbar->display(),
            'listing' => $listing->display(),
            'cancel' => $cancel,
        );
        echo $this->render(dirname(__FILE__) . self::TEMPLATE_3, $params);
    }

    /**
     * Displays the second page.
     * For a POST processes the subscribers.
     */
    protected function actionDisplayUsers()
    {
        $this->model->setProperties($_SESSION[self::PLUGIN]);

        if (isset($_POST['submit'])) {
            $result = $this->processUsers($this->model->users, $this->model->command, $this->model->listId);
            $this->redirectExit(new PageURL(), array('result' => $result));
        }

        $this->toolbar->addHelpButton('help');
        $cancel = new CommonPlugin_PageLink(new PageURL(null), 'Cancel', array('class' => 'button'));
        $params = array(
            'toolbar' => $this->toolbar->display(),
            'commandList' => $this->radioButtonList(self::HTML_DISABLED),
            'listSelect' => $this->dropDownList(self::HTML_DISABLED),
            'userArea' => CHtml::textArea('users', implode("\n", $this->model->users),
                array('rows' => '20', 'cols' => '30', 'disabled' => self::HTML_DISABLED)
            ),
            'formURL' => new PageURL(null, array('action' => 'displayUsers')),
            'cancel' => $cancel,
        );
        echo $this->render(dirname(__FILE__) . self::TEMPLATE_2, $params);
    }

    /**
     * Displays the first page including any error or result message.
     * For a POST validates the submission. On success redirects to the second page.
     */
    protected function actionDefault()
    {
        $params = array();

        if (isset($_POST['submit'])) {
            $error = '';

            switch ($_POST['submit']) {
                case 'Upload':
                    $error = $this->validateFile();

                    if ($error == '') {
                        $users = $this->loadUsersFromFile();
                    }
                    break;
                case 'Match':
                    if ($this->model->pattern == '') {
                        $error = $this->i18n->get('error_match_not_entered');
                        break;
                    }
                    $users = $this->dao->matchUsers(
                        $this->model->pattern,
                        $this->model->command == self::COMMAND_REMOVE
                            ? $this->model->listId
                            : null
                    );

                    if (count($users) == 0) {
                        $error = $this->i18n->get('error_no_match', $this->model->pattern);
                        break;
                    }
                    break;
                default:
                    $error = 'unrecognised submit ' . $_POST['submit'];
            }

            if (!$error) {
                $this->redirectExit(
                    new PageURL(null, array('action' => 'displayUsers')),
                    array(
                        'users' => $users,
                        'command' => $this->model->command,
                        'listId' => $this->model->listId,
                    )
                );
            }
            $params['error'] = $error;
        }

        if (isset($_SESSION[self::PLUGIN]['result'])) {
            $params['result'] = $_SESSION[self::PLUGIN]['result'];
        }
        unset($_SESSION[self::PLUGIN]);

        $this->toolbar->addHelpButton('help');
        $params += array(
            'toolbar' => $this->toolbar->display(),
            'formURL' => new PageURL(),
            'validateURL' => new PageURL(null, array('action' => 'validate')),
            'commandList' => $this->radioButtonList(self::HTML_ENABLED),
            'listSelect' => $this->dropDownList(self::HTML_ENABLED),
        );
        echo $this->render(dirname(__FILE__) . self::TEMPLATE, $params);
    }

    public function __construct()
    {
        parent::__construct();
        $this->dao = new SubscribersPlugin_DAO_Action(new DB());
        $this->model = new SubscribersPlugin_Model_Action(self::COMMAND_UNCONFIRM);
        $this->model->setProperties($_REQUEST);
        $this->toolbar = new Toolbar($this);
    }
}