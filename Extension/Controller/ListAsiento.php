<?php
/**
 * This file is part of AsientoPredefinido plugin for FacturaScripts
 * Copyright (C) 2021-2024 Carlos Garcia Gomez            <carlos@facturascripts.com>
 *                    Jeronimo Pedro Sánchez Manzano <socger@gmail.com>
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

class ListAsiento
{

    // createViews() se ejecuta una vez realizado el createViews() del controlador.
    public function createViews()
    {
        return function () {
            $this->createViewsAsientosPredefinidos();

            // Esto es para añadir un filtro en la pestaña ListAsiento
            $asientosPre = $this->codeModel->all('asientospre', 'id', 'descripcion');
            $this->addFilterSelect('ListAsiento', 'idasientopre', 'predefined-acc-entries', 'idasientopre', $asientosPre);
        };
    }

    protected function createViewsAsientosPredefinidos()
    {
        return function (string $viewName = 'ListAsientoPredefinido') {
            $this->addView($viewName, 'AsientoPredefinido', 'predefined-acc-entries', 'fa-solid fa-blender');
            $this->addOrderBy($viewName, ["id"], "code");
            $this->addOrderBy($viewName, ["descripcion"], "description", 1);
            $this->addSearchFields($viewName, ["id", "concepto", "descripcion"]);
        };
    }
}
