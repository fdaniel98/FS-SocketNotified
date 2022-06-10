<?php

namespace FacturaScripts\Plugins\SocketNotified\Extension\Model;

use Closure;

class FacturaCliente
{
    public function ordenId(): Closure
    {
        return function () {
            return $this->ordenId;
        };
    }

    public function estadoPedido(): Closure
    {
        return function () {
            return $this->estadoPedido;
        };
    }
}