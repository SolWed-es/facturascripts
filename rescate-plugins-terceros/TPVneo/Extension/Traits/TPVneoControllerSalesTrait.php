<?php
/**
 * Copyright (C) 2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Extension\Traits;

use Closure;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;

use FacturaScripts\Dinamic\Model\TpvCaja;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
trait TPVneoControllerSalesTrait
{
    public function autocompleteTPVneoBoxAction(): Closure
    {
        return function ($query) {
            $list = [];
            $boxModel = new TpvCaja();
            $where = [
                new DataBaseWhere('fechaini', $query, 'LIKE'),
                new DataBaseWhere('fechafin', null),
            ];
            foreach ($boxModel->all($where, ['fechaini' => 'ASC'], 0, 0) as $box) {
                $list[] = [
                    'key' => $box->idcaja,
                    'value' => $box->fechaini
                ];
            }

            if (empty($list)) {
                $list[] = ['key' => null, 'value' => Tools::lang()->trans('no-data')];
            }

            return $list;
        };
    }
    public function execPreviousAction(): Closure
    {
        return function ($action) {
            if ($action === 'autocomplete-tpvneo-box') {
                $this->setTemplate(false);
                $query = (string)$this->request->get('term', '');
                $this->response->setContent(json_encode($this->autocompleteTPVneoBoxAction($query)));
                return false;
            }
        };
    }
}