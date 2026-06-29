<?php

declare(strict_types=1);

namespace RozetkaPay\RozetkaPay\Model\Gateway\Response;

use Magento\Sales\Model\Order;
use RozetkaPay\RozetkaPay\Api\CallbackInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use RozetkaPay\RozetkaPay\Logger\Logger as RozetkaInfoLogger;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use RozetkaPay\RozetkaPay\Model\Config\Provider;
use RozetkaPay\RozetkaPay\Model\Gateway\Request\CreatePayment;
use Magento\Sales\Model\Order\Payment\Transaction\Builder as TransactionBuilder;

class Callback implements CallbackInterface
{
    const SUCCESS = 'transaction_successful';

    const SIGNATURE_HEADER = 'X-Rozetkapay-Signature';

    private $request;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var SerializerInterface
     */
    protected $serializer;

    /**
     * @var RozetkaInfoLogger
     */
    protected $rozetkaLogger;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var InvoiceService
     */
    protected $invoiceService;

    /**
     * @var Transaction \
     */
    protected $transaction;

    /**
     * @var InvoiceSender
     */
    protected $invoiceSender;

    /**
     * @var Provider
     */
    protected $rozetkaConfig;

    /**
     * @var TransactionBuilder
     */
    protected $transactionBuilder;

    /**
     * @param RequestInterface $request
     * @param OrderRepositoryInterface $orderRepository
     * @param SerializerInterface $serializer
     * @param RozetkaInfoLogger $rozetkaLogger
     * @param LoggerInterface $logger
     * @param InvoiceService $invoiceService
     * @param Transaction $transaction
     * @param InvoiceSender $invoiceSender
     * @param Provider $rozetkaConfig
     * @param TransactionBuilder $transactionBuilder
     */
    public function __construct(
        RequestInterface $request,
        OrderRepositoryInterface $orderRepository,
        SerializerInterface $serializer,
        RozetkaInfoLogger $rozetkaLogger,
        LoggerInterface $logger,
        InvoiceService $invoiceService,
        Transaction $transaction,
        InvoiceSender $invoiceSender,
        Provider $rozetkaConfig,
        TransactionBuilder $transactionBuilder
    )
    {
        $this->request = $request;
        $this->orderRepository = $orderRepository;
        $this->serializer = $serializer;
        $this->rozetkaLogger = $rozetkaLogger;
        $this->logger = $logger;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
        $this->invoiceSender = $invoiceSender;
        $this->rozetkaConfig = $rozetkaConfig;
        $this->transactionBuilder = $transactionBuilder;
    }

    /**
     * @return void
     */
    public function execute()
    {
        try {
            $rawBody = $this->request->getContent();
            if (!$this->verifySignature($rawBody)) {
                $this->logger->critical('RozetkaPay callback: wrong signature');
                return;
            }
            $params = $this->serializer->unserialize($rawBody);
            $this->rozetkaLogger->info('Callback', $params);
            $orderId = $params['external_id'];
            if ($this->rozetkaConfig->isSandboxMode()) {
                $orderId = str_replace(CreatePayment::SANDBOX_ORDER_MASK, '', $orderId);
            }
            $order = $this->orderRepository->get($orderId);
            $payment = $order->getPayment();
            if($params['details']['status_code'] === self::SUCCESS &&
                $payment->getAdditionalInformation('transaction_id') === $params['id']
            ) {
                $this->createInvoice($order, $params);
                $this->createTransaction($order, $params);
                $order->setState(Order::STATE_PROCESSING)->setStatus(Order::STATE_PROCESSING);
                $this->orderRepository->save($order);
            }
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage());
        }
    }

    /**
     * Verify callback signature.
     *
     * Algorithm: base64url(sha1(password . rawBody . password, raw_binary)).
     *
     * @param string $rawBody
     * @return bool
     */
    private function verifySignature($rawBody)
    {
        $password = (string) $this->rozetkaConfig->getShopPass();
        $original = (string) $this->request->getHeader(self::SIGNATURE_HEADER);
        $calculated = strtr(base64_encode(sha1($password . $rawBody . $password, true)), '+/', '-_');

        return hash_equals($calculated, $original);
    }

    /**
     * @param $order
     * @param $response
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function createInvoice($order, $response)
    {
        if ($order->canInvoice()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->register();
            $invoice->setState(2)->save();
            $this->invoiceSender->send($invoice);
            $order->addStatusHistoryComment(__('Notified customer about invoice creation #%1.', $invoice->getId()))
                ->setIsCustomerNotified(true)
                ->save();
        }
    }

    /**
     * @param $order
     * @param $paymentData
     * @return void
     */
    private function createTransaction($order = null, $paymentData = array())
    {
        try {
            //get payment object from order object
            $payment = $order->getPayment();
            $payment->setLastTransId($paymentData['details']['transaction_id']);
            $payment->setTransactionId($paymentData['details']['transaction_id']);
            $payment->setAdditionalInformation(
                [\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => (array) $paymentData]
            );
            $formatedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );
            $message = __('The authorized amount is %1.', $formatedPrice);
            //get the object of builder class
            $trans = $this->transactionBuilder;
            $transaction = $trans->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($paymentData['details']['transaction_id'])
                ->setAdditionalInformation(
                    [\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => (array) $paymentData]
                )
                ->setFailSafe(true)
                //build method creates the transaction and returns the object
                ->build(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE);
            $payment->addTransactionCommentsToOrder(
                $transaction,
                $message
            );
            $payment->setParentTransactionId(null);
            $payment->save();
            $order->save();
            $transaction->save();
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage());
        }
    }
}
