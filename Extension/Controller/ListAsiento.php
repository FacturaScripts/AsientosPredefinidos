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
        };
    }
}
