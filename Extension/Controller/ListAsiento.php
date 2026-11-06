<?php
/**
 * This file is part of AsientoPredefinido plugin for FacturaScripts
 * Copyright (C) 2021-2026 Carlos Garcia Gomez            <carlos@facturascripts.com>
 *                         Jeronimo Pedro Sánchez Manzano <socger@gmail.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\AsientosPredefinidos\Extension\Controller;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\AsientoPredefinidoRestorer;

class ListAsiento
{
    public function createViews(): Closure
    {
        return function () {
            $this->createViewsAsientosPredefinidos();

            // Esto es para añadir un filtro en la pestaña ListAsiento
            $asientosPre = $this->codeModel->all('asientospre', 'id', 'descripcion');
            $this->addFilterSelect('ListAsiento', 'idasientopre', 'predefined-acc-entries', 'idasientopre', $asientosPre);
        };
    }

    protected function createViewsAsientosPredefinidos(): Closure
    {
        return function (string $viewName = 'ListAsientoPredefinido') {
            $this->addView($viewName, 'AsientoPredefinido', 'predefined-acc-entries', 'fa-solid fa-blender')
                ->addOrderBy(['id'], 'code', 1)
                ->addOrderBy(['descripcion'], 'description')
                ->addSearchFields(['id', 'concepto', 'descripcion']);

            if ($this->user->admin) {
                $this->tab($viewName)->addButton([
                    'action' => 'restore-predefined',
                    'color' => 'warning',
                    'confirm' => true,
                    'icon' => 'fa-solid fa-trash-restore',
                    'label' => 'restore'
                ]);
            }
        };
    }

    public function execPreviousAction(): Closure
    {
        return function ($action) {
            if ($action !== 'restore-predefined') {
                return;
            }

            if (false === $this->user->admin || false === $this->permissions->allowUpdate) {
                Tools::log()->warning('not-allowed-modify');
                return;
            } elseif (false === $this->validateFormToken()) {
                return;
            }

            // restauramos las plantillas incluidas sin tocar las creadas por el usuario
            $codpais = (string)Tools::settings('default', 'codpais', 'ESP');
            if (AsientoPredefinidoRestorer::restore($codpais)) {
                Tools::log()->notice('record-updated-correctly');
            }
        };
    }
}
