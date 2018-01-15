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
 * @copyright 2011-2017 Duncan Cameron
 * @license   http://www.gnu.org/licenses/gpl.html GNU General Public License, Version 3
 */

namespace phpList\plugin\SubscribersPlugin\Controller;

use ArrayIterator;
use phpList\plugin\Common\Controller;
use phpList\plugin\Common\IExportable;
use phpList\plugin\Common\Listing;
use phpList\plugin\Common\Toolbar;
use phpList\plugin\SubscribersPlugin\SubscriberPopulator;
use phpList\plugin\SubscribersPlugin\DAO\Command as DAO;

/**
 * This class is the controller for the plugin providing the action methods.
 */
class Invalid extends Controller
{
    const PLUGIN = 'SubscribersPlugin';
    const TEMPLATE = '/../view/subscriber_report.tpl.php';
    const HELP = 'https://resources.phplist.com/plugin/subscribers?&#subscriber_reports';

    protected $dao;

    private function invalidSubscribers()
    {
        $invalid = [];

        foreach ($this->dao->allUsers() as $row) {
            if (!is_email($row['email'])) {
                $invalid[] = $row;
            }
        }

        return new ArrayIterator($invalid);
    }

    /**
     * Validates the email address of each subscriber and displays those that are invalid.
     */
    protected function actionDefault()
    {
        $params = [];
        $iterator = $this->invalidSubscribers();

        if (count($iterator) == 0) {
            $params['warning'] = $this->i18n->get('All subscribers have a valid email address');
        }
        $populator = new SubscriberPopulator(
            $this->i18n,
            $iterator,
            $this->i18n->get('Subscribers with an invalid email address')
        );
        $listing = new Listing($this, $populator);
        $toolbar = new Toolbar($this);
        $toolbar->addExportButton(['report' => $_GET['report']]);
        $toolbar->addExternalHelpButton(self::HELP);

        $params['listing'] = $listing->display();
        $params['toolbar'] = $toolbar->display();
        echo $this->render(dirname(__FILE__) . self::TEMPLATE, $params);
    }

    protected function actionExportCSV(IExportable $exportable = null)
    {
        $populator = new SubscriberPopulator($this->i18n, $this->invalidSubscribers(), 'invalid_email');
        parent::actionExportCSV($populator);
    }

    public function __construct(DAO $dao)
    {
        parent::__construct();
        $this->dao = $dao;
    }
}
