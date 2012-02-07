<?php

/**
 * The cron controller file.
 *
 * @version	   $Id:  $
 * @copyright  Copyright (C) 2011 Migur Ltd. All rights reserved.
 * @license	   GNU General Public License version 2 or later; see LICENSE.txt
 */
// No direct access to this file
defined('_JEXEC') or die('Restricted access');

// import Joomla controllerform library
jimport('joomla.application.component.controllerform');
jimport('migur.library.mailer');
jimport('migur.library.mailer.mailbox');
jimport('joomla.session.session');

JLoader::import('helpers.autocompleter', JPATH_COMPONENT_ADMINISTRATOR, '');
JLoader::import('tables.sent',           JPATH_COMPONENT_ADMINISTRATOR, '');
JLoader::import('tables.subscriber',     JPATH_COMPONENT_ADMINISTRATOR, '');
JLoader::import('tables.queue',          JPATH_COMPONENT_ADMINISTRATOR, '');
JLoader::import('models.automailing.manager', JPATH_COMPONENT_ADMINISTRATOR, '');

/**
 * Class of the cron controller. Handles the  request of a "trigger" from remote server.
 *
 * @since   1.0
 * @package Migur.Newsletter
 */
class NewsletterControllerCron extends JControllerForm
{
	
	protected $_isAdminAuthorized = null;
	
	
	/**
	 * The constructor of a class
	 *
	 * @param	array	$config		An optional associative array of configuration settings.
	 *
	 * @return	void
	 * @since	1.0
	 */
	public function __construct($config = array())
	{
		parent::__construct($config);
	}

	
	/**
	 * Main entry point for triggering all cronjobs
	 * 
	 * Cron usage: 
	 *  curl [BASE_URL]/index.php\?option=com_newsletter\&task=cron.send
	 *  wget --delete-after [BASE_URL]/index.php\?option=com_newsletter\&task=cron.send
	 *
	 * @return void
	 * @since  1.0
	 */
	public function send() 
	{
		$res = array();

		// First check if we need to process some automailing items.
		// It does not take much time...
		try {
			$res['automailing'] = $this->automailing('triggered');
		} catch (Exception $e){
			$res['automailing'] = array('error' => $e->getMessage());
		}	
		
		
		try {
			$res['mailing'] = $this->mailing('triggered');
		} catch (Exception $e){
			$res['mailing'] = array('error' => $e->getMessage());
		}	

		try {
			$res['processBounced'] = $this->processBounced('triggered');
		} catch (Exception $e){
			$res['processBounced'] = array('error' => $e->getMessage());
		}	
		
		NewsletterHelper::logMessage(json_encode($res), 'cron/');
		jexit();
	}

	
	/**
	 * Sends the bulk of letters to queued subscribers.
	 * 
	 * Cron usage: 
	 *  curl [BASE_URL]/index.php\?option=com_newsletter\&task=cron.mailing
	 *  wget --delete-after [BASE_URL]/index.php\?option=com_newsletter\&task=cron.mailing
	 *
	 * @return void
	 * @since  1.0
	 */
	public function mailing($mode = 'std')
	{
		ob_start();

		$config   = JComponentHelper::getParams('com_newsletter');
		$doSave   = (bool) $config->get('newsletter_save_to_db');

		// 1. Get all SMTP profiles for which are data in queue.
		$model = JModel::getInstance('Newsletters', 'NewsletterModel');
		$smtpProfiles = $model->getUsedInQueue();
		$response = array();
		// 2. Get process it in cycle
		if (!empty($smtpProfiles)) {

			$queueManager = JModel::getInstance('Queues', 'NewsletterModel');
			$queueItem    = JModel::getInstance('Queue',      'NewsletterModelEntity');
			$subscriber   = JModel::getInstance('Subscriber', 'NewsletterModelEntity');
			$newsletter   = JModel::getInstance('Newsletter', 'NewsletterModelEntity');
			
			foreach($smtpProfiles as $smtpProfile) {

				$responseItem = array(
					'profile' => array(
						'id'    => $smtpProfile->smtp_profile_id,
						'name'  => $smtpProfile->smtp_profile_name,
						'email' => $smtpProfile->username ),
					'processed' => 0,
					'success'   => 0,
					'data' => array(),
					'error' => '');

				// Need to send notice if system cannot send the requiered count of 
				// letters for mailing interval.
				if ($smtpProfile->isNeedNewPeriod() && $smtpProfile->needToSendCount() > 0) {
					NewsletterHelper::logMessage(
						JText::_('COM_NEWSLETTER_NOTICE_SENDING_INTERVAL_TOO_SHORT'),
						'mailing');
				}
				
				// First check if the process is not hanged up
				if($smtpProfile->isInProcess() || $smtpProfile->isNeedNewPeriod()) {
					$smtpProfile->kill();
				}
				
				// Check if mailing from this SMTP is in progress (in other thread)
				if (!$smtpProfile->isInProcess()) {
					
					// Before we start to process SMTPP we need to set execution flag
					$smtpProfile->setInProcess(1);

					// Check if it is time for new period.
					// If it triggered by admin manualy then start process anyway.
					if ($smtpProfile->isNeedNewPeriod() || $this->_isAdminAuthorized()) {
						$smtpProfile->startNewPeriod();
					}

					// Process mails of this SMTP profile only if we need it
					if ($smtpProfile->needToSendCount() > 0) {
						echo "{$smtpProfile->needToSendCount()}\n";

						// Get mails that we need to send
						$queueItems = $queueManager->getUnsentSidNidBySmtp($smtpProfile->smtp_profile_id, $smtpProfile->needToSendCount());
						$ret = array();
						if (!empty($queueItems)) {

							$mailer = new MigurMailer();

							// Let's process these mails
							foreach ($queueItems as $qi) {

								$letter = new stdClass();

								$queueItem->setFromArray($qi);
								
								try {

									// Let's load subscriber. Exception on fail.
									if (!$subscriber->load($queueItem->subscriber_id)) {
										throw new Exception(JText::_('COM_NEWSLETTER_SUBSCRIBER_NOT_FOUND'));
									}

									// Let's load newsletter. Exception on fail.
									if(!$newsletter->load($queueItem->newsletter_id)) {
										throw new Exception(JText::_('COM_NEWSLETTER_NEWSLETTER_NOT_FOUND'));
									}

									// Send mail. Exception from mailer on fail.
									$letter = $mailer->send(array(
										'subscriber'    => $subscriber->toObject(),
										'newsletter_id' => $queueItem->newsletter_id,
										'type'          => $subscriber->getType()
									));

									// Now all good and we can update informtion 
									// about this mailing

									// Set up the sending start time
									$newsletter->updateSentTime();

									// Get all records which refers
									// to the current user and the current newsletter
									$group = $queueManager->getItemsByFilter(array(
										'newsletter_id' => $queueItem->newsletter_id,
										'subscriber_id' => $queueItem->subscriber_id
									));

									// Process all lists in which the user is present
									foreach ($group as $groupItem) {

										// Add notes to history for each list
										$history = JTable::getInstance('history', 'NewsletterTable');
										$history->save(array(
											'newsletter_id' => $groupItem->newsletter_id,
											'subscriber_id' => $groupItem->subscriber_id,
											'list_id'       => $groupItem->list_id,
											'date'			=> date('Y-m-d H:i:s'),
											'action'		=> $letter->state?
												NewsletterTableHistory::ACTION_SENT :
												NewsletterTableHistory::ACTION_BOUNCED,
											'text'			=> ''
										));
										unset($history);


										// Add the sent letter to the sents for each list
										if ($doSave) {
											
											$sent = JTable::getInstance('sent', 'NewsletterTable');
											$sent->save(array(
												'newsletter_id' => $groupItem->newsletter_id,
												'subscriber_id' => $groupItem->subscriber_id,
												'list_id'       => $groupItem->list_id,
												'sent_date'     => date('Y-m-d H:i:s'),
												'bounced'       => ($letter->state)?
													NewsletterTableSent::BOUNCED_NO :
													NewsletterTableSent::BOUNCED_SOFT,

												'html_content'      => ($subscriber->getType() == 'html') ? $letter->content : "",
												'plaintext_content' => ($subscriber->getType() == 'plain') ? $letter->content : "",
												'extra' => array(
													'to' => $subscriber->email,
													'error' => $letter->errors)));
											
											unset($sent);
										}
									}

									$smtpProfile->updateSentsPerPeriodCount();

								} catch(Exception $e) {
									$ret[] = array(
										'newsletter_id' => $queueItem->newsletter_id,
										'email'         => $subscriber->email,
										'subscriber_id' => $subscriber->subscriber_id,
										'error'         => $e->getMessage()
									);
								}
								
								// Update the queue item after mailing
								$queueManager->updateState(
									!empty($letter->state)? 0 : 2,
									$queueItem->newsletter_id,
									$queueItem->subscriber_id );
								
								$responseItem['processed']++;
								$responseItem['success'] += !empty($letter->state)? 1 : 0;
							}
						}

						NewsletterHelper::logMessage(json_encode($ret), 'cron/');
						$responseItem['data'] = $ret;
					}

					// Finish to process SMTPP by shouting down the execution flag
					$smtpProfile->setInProcess(0);
					
				} else {
					$responseItem['error'] = 'In process';
				}
				
				$response[] = $responseItem;
			}
		}

		if ($mode == 'std') {
			// Send and exit
			NewsletterHelper::jsonMessage('ok', $response);
			
		} else {
			return $response;
		}	
	}

	
	/**
	 * Process mailboxes for presence of bounced mails.
	 * 
	 * curl [BASE_URL]/index.php\?option=com_newsletter\&task=cron.processbounced
	 * wget --delete-after [BASE_URL]/index.php\?option=com_newsletter\&task=cron.processbounced
	 */
	public function processbounced($mode = 'std')
	{
		ob_start();

		$config   = JComponentHelper::getParams('com_newsletter');
		
		$doSave   = (bool) $config->get('newsletter_save_to_db');
		$count    = (int)  $config->get('mailer_cron_count');

		if ($this->_checkAccess('mailer_cron_bounced')) {

			$table = JTable::getInstance('jextension', 'NewsletterTable');

			// set the isExecuted flag
			if ($table->load(array('name' => 'com_newsletter'))) {
				$table->addToParams(array('mailer_cron_bounced_is_executed' => 1));
				$table->store();
			}
			
			$bounceds = JModel::getInstance('Bounceds', 'NewsletterModel');

			$mbprofiles = $bounceds->getMailboxesForBounsecheck();

			$limit = JRequest::getInt('limit', 100);
			
			$processedAll = 0;
			$response = array();
			// Trying to check all bounces
			foreach($mbprofiles as $mbprofile) {

				$processed = 0;
				$response[$mbprofile['username']] = array(
					'errors' => array(),
					'processed' => 0
				);

				try {

					$mailbox = new MigurMailerMailbox(&$mbprofile);
					$mailbox->useCache();
					
					$mails = $mailbox->getBouncedList($limit);

					if ($mails === false) {

						$response[$mbprofile['username']]['errors'][] = $mailbox->getLastError();

					} else {

						if (!empty($mails)) {

							foreach($mails as &$mail) {

								if (!empty($mail->subscriber_id) && !empty($mail->newsletter_id) && !empty($mail->bounce_type)) 
								{
									$queue = JTable::getInstance('Queue', 'NewsletterTable');
									if ($queue->setBounced($mail->subscriber_id, $mail->newsletter_id, NewsletterTableQueue::STATE_BOUNCED))
									{
										$sent = JModel::getInstance('Sent', 'NewsletterModel');
										$sent->setBounced($mail->subscriber_id, $mail->newsletter_id, $mail->bounce_type);

										$history = JModel::getInstance('History', 'NewsletterModel');
										$history->setBounced($mail->subscriber_id, $mail->newsletter_id, $mail->bounce_type);

										if ($mail->msgnum > 0) {
											
											if (!$mailbox->deleteMail($mail->msgnum)) {
												throw new Exception('Delete message error.');
											}
											
											NewsletterHelper::logMessage('Mailbox.Delete mail.Position:'.$mail->msgnum, 'cron/');
											$processed++;
											$processedAll++;
										}

										unset($history);
										unset($sent);
									}
									unset($queue);
								}
							}
						}

						//Set summary information
						$response[$mbprofile['username']]['processed'] = $processed;
						$response[$mbprofile['username']]['found'] = $mailbox->found;
						$response[$mbprofile['username']]['total'] = $mailbox->total;
						$response[$mbprofile['username']]['totalBounces'] = $mailbox->totalBounces;
						//$response[$mbprofile['username']]['lastPosition'] = $mailbox->lastPosition;
					}

					// Update the state of mailbox
					if ($mails !== false) {
						$mbtable = JTable::getInstance('Mailboxprofile', 'NewsletterTable');
						$mbtable->save($mailbox->mailboxProfile);
						unset($mbtable);
					}	
					
				} catch(Exception $e) {

					$response[$mbprofile['username']]['errors'][] = $e->getMessage();
					$response[$mbprofile['username']]['errors'][] = JText::_('COM_NEWSLETTER_CHECK_YOUR_MAILBOX_SETTINGS');
					$error = true;
				}	

				// Close mailbox and destroy
				if (!empty($mailbox)) {
					$mailbox->close();
				}	
				unset($mailbox);
			}

			if ($table) {
				
				$table->addToParams(array(
					'mailer_cron_bounced_is_executed' => 0,
					'mailer_cron_bounced_last_execution_time' => date('Y-m-d H:i:s')));
				$table->store();
			}

			NewsletterHelper::logMessage(json_encode($response), 'cron/');

			
		} else {

			$isExec = (bool) $config->get('mailer_cron_bounced_is_executed');
			if (!$isExec) {
				$response = array('errors' => array(JText::_('COM_NEWSLETTER_BOUNCE_HANDLING_INTERVAL_IS_NOT_EXEDED')));
			} else {
				$response = array('errors' => array(JText::_('COM_NEWSLETTER_BOUNCE_HANDLING_IS_IN_PROCESS_NOW')));
			}	
		}
        
		if ($mode == 'std') {
			ob_end_clean();
			echo json_encode($response);
			jexit();
			
		} else {
			return $response;
		}	
	}
	
	/**
	 * 
	 * 
	 * @param type $type "mailer_cron", "mailer_cron_bounced"
	 */
	protected function _checkAccess($type)
	{
		$config   = JComponentHelper::getParams('com_newsletter');
		$isExec   = (bool) $config->get($type.'_is_executed');

		$lastExec = $config->get($type.'_last_execution_time');
		$lastExec = !empty($lastExec) ? strtotime($lastExec) : 0;

		$interval = (int)  $config->get('mailer_cron_interval');
		$interval = $interval * 60;
		
		// Pre check if the isExec is too long
		$table = JTable::getInstance('jextension', 'NewsletterTable');
		
		if ($isExec && $table->load(array('name' => 'com_newsletter'))) {
			
			// If execution does about 10 times of interval then forse to set $isExec = 0
			if ($lastExec == 0 || ((time() - $lastExec) > $interval*10)) {
			
				$table->addToParams(array($type.'_is_executed' => 0));
				$table->store();
				$isExec = false;
			}
		}

		$forced = JRequest::getBool('forced', false);
		if($forced) {
                    
			$conf = JFactory::getConfig();
			$handler = $conf->get('session_handler', 'none');
			$sessId = JRequest::getVar(JRequest::getString('sessname', ''), false, 'COOKIE');
			if(empty($sessId)){
				return false; //'Unknown session';
			}    
			$data = JSessionStorage::getInstance($handler, array())->read($sessId);
			session_decode($data);
			$user = $_SESSION['__default']['user'];
				$levels = $user->getAuthorisedGroups();
			if ( max($levels) < 7 ) {
				return false; //'Unauthorized user';
			}    
		}
		return (($lastExec + $interval < time()) || $forced) && !$isExec;
	}
	

	public function _isAdminAuthorized()
	{
		if ($this->_isAdminAuthorized !== null) {
			return $this->_isAdminAuthorized;
		}
		
		if(JRequest::getBool('forced', false)) {
                    
			$conf = JFactory::getConfig();
			$handler = $conf->get('session_handler', 'none');
			$sessId = JRequest::getVar(JRequest::getString('sessname', ''), false, 'COOKIE');
			
			if(!empty($sessId)){
				
				$data = JSessionStorage::getInstance($handler, array())->read($sessId);

				// Save session
				$sessTmp = $_SESSION;
				$_SESSION = array();
				session_decode($data);
				$user = $_SESSION['__default']['user'];
				$_SESSION = $sessTmp;
				$this->_isAdminAuthorized = (bool)$user->authorise('core.admin');
			} else {
				$this->_isAdminAuthorized = false;
			}
			
			return $this->_isAdminAuthorized;
		}
		
		return false;
	}
	
	public function automailing($mode = 'std'){
		
		/** 
		 * Has 3 phases:
		 * 1. Create new threads if needed
		 * 
		 * 2. Process all active threads (start, continue, destroy)
		 * 
		 * 3. Sanitize threads registry (#__automailing_threads)
		 */
		
		// Phase #1
		$response = array('plansStarted' => 0);
		
		$plans = NewsletterAutomailingManager::getScheduledPlans();
		
		if (!empty($plans)) {
			foreach($plans as $plan) {
				// The plan entity can decide if it is time to start or not
				// If yes then it creates an new thread based on this plan.
				if (empty($plan->automailing_state)) {
					if ($plan->start() === true) {
						$response['plansStarted']++;
					}
				}	
			}
		}
		
		// Phase #2
		$threads = NewsletterAutomailingManager::getAutomailingThreads();
		
		if (!empty($threads)) {
			foreach($threads as $thread) {
				// Execute the thread. "WakeUp" in other words.
				// All functionality is incapsulated by thread. There is trigger only
				$thread->run();
			}
		}
		
		$response['threadsProcessed'] = count($threads);
		
		// Phase #3 ...........
		
		if ($mode == 'std') {
			NewsletterHelper::logMessage('Automailing.Finished: '.json_encode($response), 'automailing/');
			NewsletterHelper::jsonResponse('ok', '', $response);
		} else {
			return $response;
		}	
	}
}

