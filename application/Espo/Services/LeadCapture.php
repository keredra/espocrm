<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2019 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Services;

use \Espo\ORM\Entity;

use Espo\Core\Utils\Util;

use \Espo\Core\Exceptions\NotFound;
use \Espo\Core\Exceptions\BadRequest;
use \Espo\Core\Exceptions\Error;

class LeadCapture extends Record
{
    protected $readOnlyAttributeList = ['apiKey'];

    protected function init()
    {
        $this->addDependency('fieldManagerUtil');
        $this->addDependency('container');
        $this->addDependency('defaultLanguage');
        $this->addDependency('hookManager');
    }

    protected function getMailSender()
    {
        return $this->getInjection('container')->get('mailSender');
    }

    public function prepareEntityForOutput(Entity $entity)
    {
        parent::prepareEntityForOutput($entity);

        $entity->set('exampleRequestMethod', 'POST');

        $requestUrl = $this->getConfig()->getSiteUrl() . '/api/v1/LeadCapture/' . $entity->get('apiKey');
        $entity->set('exampleRequestUrl', $requestUrl);

        $fieldManagerUtil = $this->getInjection('fieldManagerUtil');

        $requestPayload = "```{\n";

        $attributeList = [];

        $attributeIgnoreList = ['emailAddressIsOptedOut', 'phoneNumberIsOptedOut', 'emailAddressData', 'phoneNumberData'];

        $fieldList = $entity->get('fieldList');
        if (is_array($fieldList)) {
            foreach ($fieldList as $field) {
                foreach ($fieldManagerUtil->getActualAttributeList('Lead', $field) as $attribute) {
                    if (!in_array($attribute, $attributeIgnoreList)) {
                        $attributeList[] = $attribute;
                    }
                }
            }
        }

        foreach ($attributeList as $i => $attribute) {
            $requestPayload .= "    " . $attribute . ": " . strtoupper(Util::camelCaseToUnderscore($attribute));
            if ($i < count($attributeList) - 1) {
                $requestPayload .= ",";
            }

            $requestPayload .= "\n";
        }

        $requestPayload .= '}```';
        $entity->set('exampleRequestPayload', $requestPayload);
    }

    protected function beforeCreateEntity(Entity $entity, $data)
    {
        $apiKey = $this->generateApiKey();
        $entity->set('apiKey', $apiKey);
    }

    public function generateNewApiKeyForEntity($id)
    {
        $entity = $this->getEntity($id);
        if (!$entity) throw new NotFound();

        $apiKey = $this->generateApiKey();
        $entity->set('apiKey', $apiKey);

        $this->getEntityManager()->saveEntity($entity);

        $this->prepareEntityForOutput($entity);

        return $entity;
    }

    public function generateApiKey()
    {
        return \Espo\Core\Utils\Util::generateApiKey();
    }

    public function isApiKeyValid($apiKey)
    {
        $leadCapture = $this->getEntityManager()->getRepository('LeadCapture')->where([
            'apiKey' => $apiKey,
            'isActive' => true
        ])->findOne();

        if ($leadCapture) return true;

        return false;
    }

    public function leadCapture($apiKey, $data)
    {
        $leadCapture = $this->getEntityManager()->getRepository('LeadCapture')->where([
            'apiKey' => $apiKey,
            'isActive' => true
        ])->findOne();

        if (!$leadCapture) throw new NotFound('Api key is not valid.');

        if ($leadCapture->get('optInConfirmation')) {
            if (empty($data->emailAddress)) {
                throw new Error('LeadCapture: No emailAddress passed in the payload.');
            }
            if (!$leadCapture->get('optInConfirmationEmailTemplateId')) {
                throw new Error('LeadCapture: No optInConfirmationEmailTemplate specified.');
            }
            $lead = $this->getLeadWithPopulatedData($leadCapture, $data);

            $target = $lead;

            $duplicateData = $this->findLeadDuplicates($leadCapture, $lead);
            if ($duplicateData['lead']) $target = $duplicateData['lead'];
            if ($duplicateData['contact']) $target = $duplicateData['contact'];

            $hasDuplicate = $duplicateData['lead'] || $duplicateData['contact'];
            $isLogged = false;

            if ($hasDuplicate) {
                $this->logLeadCapture($leadCapture, $target, $data, false);
                $isLogged = true;
            }

            if ($leadCapture->get('createLeadBeforeOptInConfirmation')) {
                if (!$hasDuplicate) {
                    $this->getEntityManager()->saveEntity($lead);
                    $this->logLeadCapture($leadCapture, $target, $data, true);
                }
            }

            $lifetime = $leadCapture->get('optInConfirmationLifetime');
            if (!$lifetime) $lifetime = 100;

            $dt = new \DateTime();
            $dt->modify('+' . $lifetime . ' hours');
            $terminateAt = $dt->format('Y-m-d H:i:s');

            $uniqueId = $this->getEntityManager()->getEntity('UniqueId');
            $uniqueId->set([
                'terminateAt' => $terminateAt,
                'data' => (object) [
                    'leadCaptureId' => $leadCapture->id,
                    'data' => $data,
                    'leadId' => $lead->id,
                    'isLogged' => $isLogged,
                ],
            ]);
            $this->getEntityManager()->saveEntity($uniqueId);

            $job = $this->getEntityManager()->getEntity('Job');
            $job->set([
                'serviceName' => 'LeadCapture',
                'methodName' => 'jobOptInConfirmation',
                'data' => (object) [
                    'id' => $uniqueId->get('name'),
                ],
                'queue' => 'e0',
            ]);
            $this->getEntityManager()->saveEntity($job);

            return true;
        }

        return $this->leadCaptureProceed($leadCapture, $data);
    }

    protected function getLeadWithPopulatedData(Entity $leadCapture, $data)
    {
        $lead = $this->getEntityManager()->getEntity('Lead');

        $fieldList = $leadCapture->get('fieldList');
        if (empty($fieldList)) throw new Error('No field list specified.');

        $isEmpty = true;
        foreach ($fieldList as $field) {
            if ($field === 'name') {
                if (property_exists($data, 'name') && $data->name) {
                    $value = trim($data->name);
                    $parts = explode(' ', $value);
                    $lastName = array_pop($parts);
                    $firstName = implode(' ', $parts);
                    $lead->set('firstName', $firstName);
                    $lead->set('lastName', $lastName);
                    $isEmpty = false;
                }
                continue;
            }
            $attributeList = $this->getInjection('fieldManagerUtil')->getActualAttributeList('Lead', $field);
            if (empty($attributeList)) continue;
            foreach ($attributeList as $attribute) {
                if (property_exists($data, $attribute)) {
                    $lead->set($attribute, $data->$attribute);
                    if (!empty($data->$attribute)) {
                        $isEmpty = false;
                    }
                }
            }
        }

        if ($isEmpty) throw new BadRequest('No appropriate data in payload.');

        if ($leadCapture->get('leadSource')) {
            $lead->set('source', $leadCapture->get('leadSource'));
        }

        if ($leadCapture->get('campaignId')) {
            $lead->set('campaignId', $leadCapture->get('campaignId'));
        }

        return $lead;
    }

    protected function findLeadDuplicates(Entity $leadCapture, $lead)
    {
        $duplicate = null;
        $contact = null;

        if ($lead->get('emailAddress') || $lead->get('phoneNumber')) {
            $groupOr = [];
            if ($lead->get('emailAddress')) {
                $groupOr['emailAddress'] = $lead->get('emailAddress');
            }
            if ($lead->get('phoneNumber')) {
                $groupOr['phoneNumber'] = $lead->get('phoneNumber');
            }

            if ($lead->isNew() && $leadCapture->get('duplicateCheck')) {
                $duplicate = $this->getEntityManager()->getRepository('Lead')->where(['OR' => $groupOr])->findOne();
            }
            $contact = $this->getEntityManager()->getRepository('Contact')->where(['OR' => $groupOr])->findOne();
        }

        return [
            'contact' => $contact,
            'lead' => $duplicate,
        ];
    }

    public function leadCaptureProceed(Entity $leadCapture, $data, $leadId = null, $isLogged = false)
    {
        if ($leadId) {
            $lead = $this->getEntityManager()->getEntity('Lead', $leadId);
        } else {
            $lead = $this->getLeadWithPopulatedData($leadCapture, $data);
        }

        $campaingService = $this->getServiceFactory()->create('Campaign');

        if ($leadCapture->get('campaignId')) {
            $campaign = $this->getEntityManager()->getEntity('Campaign', $leadCapture->get('campaignId'));
        }

        $duplicate = null;
        $contact = null;
        $toRelateLead = false;

        $target = $lead;

        $duplicateData = $this->findLeadDuplicates($leadCapture, $lead);

        $duplicate = $duplicateData['lead'];
        $contact = $duplicateData['contact'];

        $targetLead = $duplicateData['lead'] ?? $lead;

        if ($contact) {
            $target = $contact;
        }

        if ($duplicate) {
            $lead = $duplicate;
            if (!$contact) {
                $target = $lead;
            }
        }

        if ($leadCapture->get('subscribeToTargetList') && $leadCapture->get('targetListId')) {
            $isAlreadyOptedIn = false;

            if ($contact) {
                if ($leadCapture->get('subscribeContactToTargetList')) {
                    $isAlreadyOptedIn = $this->isTargetOptedIn($contact, $leadCapture->get('targetListId'));
                    if (!$isAlreadyOptedIn) {
                        $this->getEntityManager()->getRepository('Contact')->relate($contact, 'targetLists', $leadCapture->get('targetListId'), [
                            'optedOut' => false,
                        ]);
                        $isAlreadyOptedIn = true;
                        if ($campaign) {
                            $campaingService->logOptedIn($campaign->id, null, $contact);
                        }

                        $targetList = $this->getEntityManager()->getEntity('TargetList', $leadCapture->get('targetListId'));
                        if ($targetList) {
                            $this->getInjection('hookManager')->process('TargetList', 'afterOptIn', $targetList, [], [
                               'link' => 'contacts',
                               'targetId' => $contact->id,
                               'targetType' => 'Contact',
                            ]);
                        }
                    }
                }
            }

            if (!$isAlreadyOptedIn) {
                if ($targetLead->isNew()) {
                    $toRelateLead = true;
                } else {
                    $isAlreadyOptedIn = $this->isTargetOptedIn($targetLead, $leadCapture->get('targetListId'));
                    if (!$isAlreadyOptedIn) {
                        $toRelateLead = true;
                    }
                }
            }
        }

        $isNew = !$duplicate && !$contact;

        if (!$contact) {
            if ($leadCapture->get('targetTeamId')) {
                $lead->addLinkMultipleId('teams', $leadCapture->get('targetTeamId'));
            }
            $this->getEntityManager()->saveEntity($lead);

            if (!$duplicate) {
                if ($campaign) {
                    $campaingService->logLeadCreated($campaign->id, $lead);
                }
            }
        }

        if ($toRelateLead && !empty($targetLead->id)) {
            $this->getEntityManager()->getRepository('Lead')->relate($targetLead, 'targetLists', $leadCapture->get('targetListId'), [
                'optedOut' => false,
            ]);
            if ($campaign) {
                $campaingService->logOptedIn($campaign->id, null, $targetLead);
            }

            $targetList = $this->getEntityManager()->getEntity('TargetList', $leadCapture->get('targetListId'));
            if ($targetList) {
                $this->getInjection('hookManager')->process('TargetList', 'afterOptIn', $targetList, [], [
                   'link' => 'leads',
                   'targetId' => $targetLead->id,
                   'targetType' => 'Lead',
                ]);
            }
        }

        if (!$isLogged) {
            $this->logLeadCapture($leadCapture, $target, $data, $isNew);
        }

        return true;
    }

    protected function isTargetOptedIn($target, $targetListId)
    {
        $isAlreadyOptedIn = $this->getEntityManager()->getRepository($target->getEntityType())->isRelated($target, 'targetLists', $targetListId);

        if ($isAlreadyOptedIn) {
            $targetList = $this->getEntityManager()->getEntity('TargetList', $targetListId);
            if ($targetList) {
                $link = null;
                if ($target->getEntityType('Contact')) $link = 'contacts';
                if ($target->getEntityType('Lead')) $link = 'leads';
                if (!$link) return false;

                $c = $this->getEntityManager()->getRepository('TargetList')->findRelated($targetList, $link, [
                    'whereClause' => [
                        'id' => $target->id,
                    ],
                    'additionalColumns' => [
                        'optedOut' => 'targetListIsOptedOut',
                    ],
                ]);

                if (count($c) && $c[0]->get('targetListIsOptedOut')) {
                    $isAlreadyOptedIn = false;
                }
            }
        }

        return $isAlreadyOptedIn;
    }

    protected function logLeadCapture($leadCapture, $target, $data, $isNew = true)
    {
        $logRecord = $this->getEntityManager()->getEntity('LeadCaptureLogRecord');
        $logRecord->set([
            'targetId' => $target->id,
            'targetType' => $target->getEntityType(),
            'leadCaptureId' => $leadCapture->id,
            'isCreated' => $isNew,
            'data' => $data,
        ]);

        if (!empty($data->description)) {
            $logRecord->set('description', $description);
        }

        $this->getEntityManager()->saveEntity($logRecord);
    }

    public function jobOptInConfirmation($jobData)
    {
        if (empty($jobData->id)) throw new Error();

        $uniqueId = $this->getEntityManager()->getRepository('UniqueId')->where([
            'name' => $jobData->id
        ])->findOne();

        if (!$uniqueId) throw new Error("LeadCapture: UniqueId not found.");

        $uniqueIdData = $uniqueId->get('data');
        if (empty($uniqueIdData->data)) throw new Error("LeadCapture: data not found.");
        if (empty($uniqueIdData->leadCaptureId)) throw new Error("LeadCapture: leadCaptureId not found.");
        $data = $uniqueIdData->data;
        $leadCaptureId = $uniqueIdData->leadCaptureId;
        $leadId = $uniqueIdData->leadId ?? null;

        $terminateAt = $uniqueId->get('terminateAt');
        if (time() > strtotime($terminateAt)) {
            throw new Error("LeadCapture: Opt-in cofrmation expired.");
        }

        $leadCapture = $this->getEntityManager()->getEntity('LeadCapture', $leadCaptureId);
        if (!$leadCapture) throw new Error("LeadCapture: LeadCapture not found.");

        $optInConfirmationEmailTemplateId = $leadCapture->get('optInConfirmationEmailTemplateId');
        if (!$optInConfirmationEmailTemplateId) throw new Error("LeadCapture: No optInConfirmationEmailTemplateId.");

        $emailTemplate = $this->getEntityManager()->getEntity('EmailTemplate', $optInConfirmationEmailTemplateId);
        if (!$emailTemplate) throw new Error("LeadCapture: EmailTemplate not found.");

        if ($leadId) {
            $lead = $this->getEntityManager()->getEntity('Lead', $leadId);
        } else {
            $lead = $this->getEntityManager()->getEntity('Lead');
            $lead->set($data);
        }

        $emailData = $this->getServiceFactory()->create('EmailTemplate')->parseTemplate($emailTemplate, [
            'Person' => $lead,
            'Lead' => $lead,
        ]);

        if (!$lead) throw new Error("Lead Capture: Could not find lead.");

        $emailAddress = $lead->get('emailAddress');
        if (!$emailAddress) throw new Error();

        $subject = $emailData['subject'];
        $body = $emailData['body'];
        $isHtml = $emailData['isHtml'];

        if (mb_strpos($body, '{optInUrl}') === false && mb_strpos($body, '{optInLink}') === false) {
            if ($isHtml) {
                $body .= "<p>{optInLink}</p>";
            } else {
                $body .= "\n\n{optInUrl}";
            }
        }

        $url = $this->getConfig()->getSiteUrl() . '/?entryPoint=confirmOptIn&id=' . $uniqueId->get('name');
        $linkHtml = '<a href='.$url.'>'.$this->getInjection('defaultLanguage')->translate('Confirm Opt-In', 'labels', 'LeadCapture').'</a>';

        $body = str_replace('{optInUrl}', $url, $body);
        $body = str_replace('{optInLink}', $linkHtml, $body);

        $email = $this->getEntityManager()->getEntity('Email');
        $email->set([
            'to' => $emailAddress,
            'subject' => $subject,
            'body' => $body,
            'isHtml' => $isHtml,
        ]);

        $this->getMailSender()->send($email);

        return true;
    }

    public function confirmOptIn($id)
    {
        $uniqueId = $this->getEntityManager()->getRepository('UniqueId')->where([
            'name' => $id
        ])->findOne();

        if (!$uniqueId) throw new NotFound("LeadCapture Confirm: UniqueId not found.");

        $uniqueIdData = $uniqueId->get('data');
        if (empty($uniqueIdData->data)) throw new Error("LeadCapture Confirm: data not found.");
        if (empty($uniqueIdData->leadCaptureId)) throw new Error("LeadCapture Confirm: leadCaptureId not found.");
        $data = $uniqueIdData->data;
        $leadCaptureId = $uniqueIdData->leadCaptureId;
        $leadId = $uniqueIdData->leadId ?? null;
        $isLogged = $uniqueIdData->isLogged ?? false;

        $terminateAt = $uniqueId->get('terminateAt');
        if (time() > strtotime($terminateAt)) {
            return (object) [
                'status' => 'expired',
                'message' => $this->getInjection('defaultLanguage')->translate('optInConfirmationExpired', 'messages', 'LeadCapture')
            ];
        }

        $leadCapture = $this->getEntityManager()->getEntity('LeadCapture', $leadCaptureId);
        if (!$leadCapture) throw new Error("LeadCapture Confirm: LeadCapture not found.");

        if (empty($uniqueIdData->isProcessed)) {
            $this->leadCaptureProceed($leadCapture, $data, $leadId, $isLogged);
            $uniqueIdData->isProcessed = true;
            $uniqueId->set('data', $uniqueIdData);
            $this->getEntityManager()->saveEntity($uniqueId);
        }

        return (object) [
            'status' => 'success',
            'message' => $leadCapture->get('optInConfirmationSuccessMessage'),
            'leadCaptureName' => $leadCapture->get('name'),
            'leadCaptureId' => $leadCapture->id,
        ];
    }
}
