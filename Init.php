<?php

namespace FacturaScripts\Plugins\SocketNotified;

use FacturaScripts\Core\Base\InitClass;
use GuzzleHttp\Client;

class Init extends InitClass
{
    public function init()
    {
        $this->loadExtension(new Extension\Model\FacturaCliente());

        if (!class_exists(Client::class)) {
            $this->toolBox()->i18nLog()->warning('Favor contactar su soporte, para terminar la instalacion de este plugin: guzzle es requerido');
        }
    }

    public function update()
    {
        // se ejecuta cada vez que se instala o actualiza el plugin.
    }
}