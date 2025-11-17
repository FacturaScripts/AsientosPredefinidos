<?php
/**
 * This file is part of AsientoPredefinido plugin for FacturaScripts
 * Copyright (C) 2021-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
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

namespace FacturaScripts\Plugins\AsientosPredefinidos\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;

/**
 * @author Carlos García Gómez            <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez       <hola@danielfg.es>
 * @author Jeronimo Pedro Sánchez Manzano <socger@gmail.com>
 */
class EditAsientoPredefinido extends EditController
{
    public function getModelClassName(): string
    {
        return 'AsientoPredefinido';
    }

    public function getPageData(): array
    {
        $page = parent::getPageData();
        $page['menu'] = 'accounting';
        $page['title'] = 'predefined-acc-entry';
        $page['icon'] = 'fa-solid fa-blender';
        return $page;
    }

    protected function createViews()
    {
        parent::createViews();

        $this->setTabsPosition('bottom');

        $this->createViewsInfo();
        $this->createViewsGenerar();
        $this->createViewsLineas();
        $this->createViewsVariables();
        $this->createViewsAsientos();
    }

    protected function createViewsGenerar(string $viewName = 'Generar'): void
    {
        $this->addHtmlView($viewName, 'AsientoPredefinidoGenerar', 'AsientoPredefinido', 'generate', 'fa-solid fa-wand-magic-sparkles');
    }

    protected function createViewsInfo(string $viewName = 'Info'): void
    {
        $this->addHtmlView($viewName, 'AsientoPredefinidoInfo', 'AsientoPredefinido', 'help', 'fa-solid fa-info-circle');
    }

    protected function createViewsLineas(string $viewName = 'EditAsientoPredefinidoLinea'): void
    {
        $this->addEditListView($viewName, 'AsientoPredefinidoLinea', 'lines')
            ->setInLine(true);
    }

    protected function createViewsVariables(string $viewName = 'EditAsientoPredefinidoVariable'): void
    {
        $this->addEditListView($viewName, 'AsientoPredefinidoVariable', 'variables', 'fa-solid fa-tools')
            ->setInLine(true);
    }

    protected function createViewsAsientos(string $viewName = 'ListAsiento'): void
    {
        $this->addListView($viewName, 'Asiento', 'generated-acc-entries', 'fa-solid fa-balance-scale')
            ->addSearchFields(['concepto', 'numero'])
            ->addOrderBy(['fecha', 'numero'], 'date', 2)
            ->addOrderBy(['numero'], 'number')
            ->addOrderBy(['importe'], 'amount')
            ->setSettings('btnNew', false);
    }

    protected function execAfterAction($action)
    {
        if ($action === 'gen-accounting') {
            $this->generateAccountingAction();
            return;
        }

        parent::execAfterAction($action);
    }

    protected function generateAccountingAction(): void
    {
        $form = $this->request->request->all();
        if (false === $this->validateFormToken()) {
            return;
        } elseif (empty($form['idempresa'])) {
            Tools::log()->warning('required-field', ['%field%' => Tools::lang()->trans('company')]);
            return;
        } elseif (empty($form['fecha'])) {
            Tools::log()->warning('required-field', ['%field%' => Tools::lang()->trans('date')]);
            return;
        }

        // Llamamos al método generate() del modelo AsientoPredefinido y le pasamos el form
        $asiento = $this->getModel()->generate($form);
        if ($asiento->exists()) {
            // Se ha creado el siento, así que sacamos mensaje, esperamos un segundo y saltamos a la dirección del asiento recién creado.
            Tools::log()->notice('generated-accounting-entries', ['%quantity%' => 1]);
            $this->redirect($asiento->url() . '&action=save-ok', 1);
            // ."&action=save-ok" es para que saque un mensaje de que registro creado ok y el parámetro 1
            // es un temporizador en redireccionar, así el usuario ve el mensaje de la línea anterior
            return;
        }

        Tools::log()->warning('record-save-error');
    }

    protected function loadData($viewName, $view)
    {
        $id = $this->getViewModelValue($this->getMainViewName(), 'id');

        switch ($viewName) {
            case 'EditAsientoPredefinidoLinea':
                $where = [new DataBaseWhere('idasientopre', $id)];
                $view->loadData('', $where, ['orden' => 'ASC', 'idasientopre' => 'ASC']);
                break;

            case 'EditAsientoPredefinidoVariable':
                $where = [new DataBaseWhere('idasientopre', $id)];
                $view->loadData('', $where, ['idasientopre' => 'ASC', 'codigo' => 'ASC']);
                break;

            case 'ListAsiento':
                $where = [new DataBaseWhere('idasientopre', $id)];
                $view->loadData('', $where);
                break;

            default:
                parent::loadData($viewName, $view);
                break;
        }
    }
}
