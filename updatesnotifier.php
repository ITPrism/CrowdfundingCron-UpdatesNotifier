<?php
/**
 * @package      Crowdfunding
 * @subpackage   Plug-ins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2015 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// no direct access
defined('_JEXEC') or die;

/**
 * Crowdfunding CRON Updates Notifier Plug-in
 *
 * @package      Crowdfunding
 * @subpackage   Plug-ins
 */
class plgCrowdfundingCronUpdatesNotifier extends JPlugin
{
    public function onCronNotify($context)
    {
        if (strcmp("com_crowdfunding.cron.notify.updates", $context) != 0) {
            return;
        }

        jimport("Prism.init");
        jimport("Crowdfunding.init");

        $period = (!$this->params->get("period", 7)) ? 7 : $this->params->get("period", 7);
        $options = array(
            "period" => $period,
            "description_length" => $this->params->get("description_length", 250),
        );

        $updates = $this->getUpdates($options);

        if (!empty($updates)) {

            // Array that will contains update IDs.
            $updatesIds = array();

            $this->loadLanguage();

            // Send messages.
            jimport("EmailTemplates.init");
            $email = new \EmailTemplates\Email();
            $email->setDb(JFactory::getDbo());
            $email->load($this->params->get("email_id"));

            if (!$email->getId()) {
                throw new RuntimeException(JText::_("PLG_CROWDFUNDINGCRON_UPDATES_NOTIFIER_ERROR_INVALID_EMAIL_TEMPLATE"));
            }

            $app       = JFactory::getApplication("site");
            $emailMode = $this->params->get("email_mode", "plain");

            // Set name and e-mail address of the sender in the mail template.
            if (!$email->getSenderName()) {
                $email->setSenderName($app->get("fromname"));
            }
            if (!$email->getSenderEmail()) {
                $email->setSenderEmail($app->get("mailfrom"));
            }

            foreach ($updates as $userId => $user) {

                $content = array();
                foreach ($user["projects"] as $project) {

                    foreach ($project["updates"] as $update) {
                        // Generate message content.
                        $content[] = "<h2>" . $update["title"] . "</h2>";
                        $content[] = $update["description"];
                        $content[] = "<hr />";

                        $updatesIds[] = $update["id"];
                    }

                    $link      = $this->params->get("domain") . JRoute::_(CrowdfundingHelperRoute::getDetailsRoute($project["slug"], $project["catslug"], "updates"));
                    $content[] = JText::sprintf("PLG_CROWDFUNDINGCRON_UPDATES_NOTIFIER_CAMPAIGN_S_S", $link, $project["title"]);
                }

                // Parse and send message to users.
                if (!empty($content)) {
                    $data = array(
                        "RECIPIENT_NAME" => $user["name"],
                        "CONTENT"        => implode("\n", $content)
                    );

                    $email->parse($data);

                    $mailer = JFactory::getMailer();
                    if (strcmp("html", $emailMode) == 0) { // Send as HTML message
                        $result = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $user["email"], $email->getSubject(), $email->getBody($emailMode), Prism\Constants::MAIL_MODE_HTML);
                    } else { // Send as plain text.
                        $result = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $user["email"], $email->getSubject(), $email->getBody($emailMode), Prism\Constants::MAIL_MODE_PLAIN);
                    }

                    if ($result !== true) {
                        throw new RuntimeException($mailer->ErrorInfo);
                    }
                }
            }

            $updatesIds = array_unique($updatesIds);
            $updates = new Crowdfunding\Updates(JFactory::getDbo());
            $updates->changeState(Prism\Constants::SENT, $updatesIds);
        }
    }

    protected function getUpdates($options = array())
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);

        // Truncate string.
        $descriptionLength = abs(Joomla\Utilities\ArrayHelper::getValue($options, "description_length", 0, "int"));
        $columnDescription =  (!$descriptionLength) ? "a.description" : 'SUBSTRING_INDEX(a.description, " ", '.(int)$descriptionLength.') as description';

        $query
            ->select(
                "a.id, a.title, ".$columnDescription.", a.record_date, a.project_id, " .
                "b.user_id"
            )
            ->from($db->quoteName("#__crowdf_updates", "a"))
            ->innerJoin($db->quoteName("#__crowdf_followers", "b") . " ON a.project_id = b.project_id")
            ->where("a.record_date >= DATE_SUB(NOW(), INTERVAL ".$options["period"]." DAY)")
            ->where("a.state = " .(int)Prism\Constants::NOT_SENT);

        $db->setQuery($query);
        $updates = (array)$db->loadAssocList();

        $results = array();
        if (!empty($updates)) {

            $projectsIds = array();
            $usersIds = array();
            foreach ($updates as $update) {
                $projectsIds[] = $update["project_id"];
                $usersIds[] = $update["user_id"];
            }

            $projectsIds = array_unique(array_filter($projectsIds));
            $usersIds = array_unique(array_filter($usersIds));

            $projects = new Crowdfunding\Projects(JFactory::getDbo());
            $projects->load(array("ids" => $projectsIds, "index" => "id"));

            $users = new Crowdfunding\User\Users(JFactory::getDbo());
            $users->load(array("ids" => $usersIds, "index" => "id"));

            foreach ($updates as $update) {
                $user = $users->getUser($update["user_id"]);

                $results[$update["user_id"]]["name"]  = (!is_null($user)) ? $user->getName() : "";
                $results[$update["user_id"]]["email"] = (!is_null($user)) ? $user->getEmail() : "";

                $project = $projects->getProject($update["project_id"]);

                $results[$update["user_id"]]["projects"][$update["project_id"]]["title"] = (!is_null($project)) ? $project->getTitle() : "";
                $results[$update["user_id"]]["projects"][$update["project_id"]]["slug"] = (!is_null($project)) ? $project->getSlug() : "";
                $results[$update["user_id"]]["projects"][$update["project_id"]]["catslug"] = (!is_null($project)) ? $project->getCatSlug() : "";

                $results[$update["user_id"]]["projects"][$update["project_id"]]["updates"][] = $update;
            }
        }

        return $results;
    }
}
