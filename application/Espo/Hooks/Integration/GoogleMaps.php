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

namespace Espo\Hooks\Integration;

use Espo\ORM\Entity;

class GoogleMaps extends \Espo\Core\Hooks\Base
{
    public function afterSave(Entity $entity)
    {
        if ($entity->id === 'GoogleMaps') {
            if (!$entity->get('enabled') || !$entity->get('apiKey')) {
                $this->getConfig()->set('googleMapsApiKey', null);
                $this->getConfig()->save();
                return;
            }
            $this->getConfig()->set('googleMapsApiKey', $entity->get('apiKey'));
            $this->getConfig()->set('googleMapsOriginPostalCode', $entity->get('originAddressPostalCode'));
            $this->getConfig()->set('googleMapsOriginStreet', $entity->get('originAddressStreet'));
            $this->getConfig()->set('googleMapsOriginState', $entity->get('originAddressState'));
            $this->getConfig()->set('googleMapsOriginCity', $entity->get('originAddressCity'));
            $this->getConfig()->set('googleMapsOriginCountry', $entity->get('originAddressCountry'));
            $this->getConfig()->set('googleMapsUseLocationService', $entity->get('useLocationService'));

            $this->getConfig()->save();
        }
    }
}