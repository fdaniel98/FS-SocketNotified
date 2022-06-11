<?php

namespace FacturaScripts\Plugins\SocketNotified\Controller;

use Exception;
use \FacturaScripts\Core\Controller\EditFacturaCliente as ParentEditFactura;

use FacturaScripts\Core\Lib\ExtendedController\EditView;
use FacturaScripts\Core\Model\LogMessage;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 *
 */
class EditFacturaCliente extends ParentEditFactura
{


    /**
     * @var Client|null
     */
    private static $client = null;
    /**
     *
     */
    private const URL = "http://162.243.165.91:8001"; // TODO: Should go on autoload .env or config.php
    /**
     * @var string
     */
    private static $token = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6ImZhY3R1cmFzY3JpcHRzIiwic2VjcmV0IjoicGFsb2xvIn0.rskOKMDf5cRwAG6p3fWs3RI6o8urCCaMIryAVjc6yp8";

    /**
     * @param  string  $className
     * @param  string  $uri
     */
    public function __construct(string $className, string $uri = '')
    {
        parent::__construct($className, $uri);

        if (self::$client === null) {
            self::$client = new Client([
                'base_uri' => self::URL,
                'timeout' => 2.0,
            ]);
        }
    }

    /**
     * @param  string  $action
     * @return bool
     * @throws GuzzleException
     */
    protected function execPreviousAction($action): bool
    {
        $res = parent::execPreviousAction($action);
        $this->notifyForAction($action);
        return $res;
    }

    protected function createViews()
    {
        parent::createViews();

        $this->addHtmlView('change-status', 'change-status', 'FacturaCliente', 'Cambio de estado', 'fas fa-code-branch');
    }


    /**
     * @param  string  $action
     * @throws GuzzleException
     */
    private function notifyForAction(string $action): void
    {
        $isPaidOnString = filter_var(intval($this->request->request->get('selectedLine')), FILTER_VALIDATE_BOOLEAN);

        // TODO: Create a version pooling (2021, 2022, etc...)
        switch ($action) {
            case 'save-paid':
                $this->handlePaidNotification($isPaidOnString);
                break;
            case 'delete-document':
            case 'delete-doc':
                $this->deleteFactura();
                break;
            case 'change-status':
                $this->updateStatusOrden();
                break;
            case 'save-document':
            case 'save-doc':
                $this->handleOrderCreation();
                break;
        }
    }

    /**
     *
     */
    private function handleOrderCreation()
    {
        try {
            $code = $this->request->get('code');
            $invoice = new FacturaCliente();
            $isUpdate = true;

            if (empty($code)) {
                $models = $invoice->all([], [], 0, 999999);;
                $code = $models[count($models) - 1]->idfactura;
                $isUpdate = false;
            }

            $invoice->loadFromCode($code);
            $lines = $invoice->getLines();

            $items = [];

            foreach ($lines as $key => $line) {
                $producto = $line->getProducto();

                array_push($items, [
                    'cantidad' => $line->cantidad,
                    'referencia' => $producto->referencia ?? "",
                    'descripcion' => $producto->observaciones ?? "",
                    'idproducto' => $line->idproducto ?? 0,
                    'precio' => $producto->precio,
                    'stock' => $producto->stockfis,
                    'img' => 'https://res.cloudinary.com/dbzzxyrze/image/upload/v1621785714/Screen_Shot_2021-05-10_at_2.35.08_PM_myjpth.png',
                ]);
            }

            $body = [
                'total' => $invoice->total,
                'neto' => $invoice->total,
                'netosindto' => $invoice->netosindto,
                'codcliente' => $invoice->codcliente,
                'codpago' => $invoice->codpago,
                'nombrecliente' => $invoice->nombrecliente,
                'tipoorden' => 'Delivery',
                'metodopago' => $this->getPaymentWay($invoice->codpago),
                'printable' => true,
                'items' => $items,
                'idfactura' => $invoice->idfactura,
                'status' => $invoice->estadoPedido ?? "Preparando",
            ];

            if ($isUpdate) {
                $codigo = $invoice->codigo;
                $res = self::$client->request('POST', "/api/v1/orders/${$codigo}/fromfacturascript", [
                    'headers' => ['Authorization' => 'Bearer '.self::$token],
                    'json' => $body,
                ]);
            } else {
                $res = self::$client->request('POST', '/api/v1/orders/fromfacturascript', [
                    'headers' => ['Authorization' => 'Bearer '.self::$token],
                    'json' => $body,
                ]);
            }

            if ($res->getStatusCode() === 200) {
                $status = $res->getStatusCode();
                $res = $res->getBody();

                $res = json_decode($res, true);

                if ($res && array_key_exists('success', $res)) {
                    $invoice->ordenId = $res['order']['orderNumber'];
                    $invoice->save();

                    // Update orden
                    $this->updateStatusOrden($invoice->codigo, $this->request->get('status'));
                } else {
                    $this->toolBox()->i18nLog()->error("code: ".$status);
                    throw new Exception("Request fail on response with status: ".$status);
                }
            } else {
                $log = new LogMessage();
                $log->message = "code: ".$res->getStatusCode();
                $log->level = 'error';
                $log->channel = 'prod';
                $log->save();
            }
        } catch (Exception $e) {
            $log = new LogMessage();
            $log->message = $e->getMessage();
            $log->level = 'error';
            $log->channel = 'prod';
            $log->save();

            $this->toolBox()->i18nLog()->error($e->getMessage());
        } catch (GuzzleException $e) {
            $log = new LogMessage();
            $log->message = $e->getMessage();
            $log->level = 'error';
            $log->channel = 'prod';
            $log->save();

            $this->toolBox()->i18nLog()->error($e->getMessage());
        }
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    private function updateStatusOrden()
    {
        try {
            $code = $this->request->get('code');

            if (empty($code)) {
                $code = $this->codeModel->code;
            }

            $invoice = new FacturaCliente();
            $invoice->loadFromCode($code);
            $orden = $invoice->codigo;
            $status = $this->request->get('status');

            $res = self::$client->put("/api/v1/orders/${orden}/fromfacturascript", [
                'status' => $status,
            ]);

            if ($res->getStatusCode() === 200) {
                $invoice->estadoPedido = $status;
                $invoice->save();
            }
        } catch (Exception $e) {
            $log = new LogMessage();
            $log->message = $e->getMessage();
            $log->level = 'error';
            $log->channel = 'prod';
            $log->save();

            $this->toolBox()->i18nLog()->error($e->getMessage());
        }
    }

    /**
     * @param $code
     * @return string
     */
    private function getPaymentWay($code): string
    {
        switch ($code) {
            case 'CONT':
                return 'Efectivo';
            default:
                return "";
        }
    }

    /**
     * @throws GuzzleException
     */
    private function deleteFactura()
    {
        try {
            $code = $this->request->get('code');

            if (empty($code)) {
                $code = $this->codeModel->code;
            }

            $invoice = new FacturaCliente();
            $invoice->loadFromCode($code);
            $orden = $invoice->codigo;

            $res = self::$client->delete("/api/v1/orders/${orden}/fromfacturascript");
        } catch (Exception $e) {
            $log = new LogMessage();
            $log->message = $e->getMessage();
            $log->level = 'error';
            $log->channel = 'prod';
            $log->save();

            $this->toolBox()->i18nLog()->error($e->getMessage());
        }
    }

}
