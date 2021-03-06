<?php
/** 
 * @category    Payments
 * @package     Openpay_Cards
 * @author      Federico Balderas
 * @copyright   Openpay (http://openpay.mx)
 * @license     http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0
 */

namespace Openpay\Cards\Controller\Payment;

use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Openpay\Cards\Model\Payment as OpenpayPayment;

/**
 * Webhook class  
 */
class Success extends \Magento\Framework\App\Action\Action
{

    protected $resultPageFactory;
    protected $request;
    protected $payment;
    protected $checkoutSession;
    protected $orderRepository;
    protected $logger;
    protected $_invoiceService;
    protected $transactionBuilder;
    
    /**
     * 
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param \Magento\Framework\App\Request\Http $request
     * @param OpenpayPayment $payment
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Psr\Log\LoggerInterface $logger_interface
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     */
    public function __construct(
            Context $context, 
            PageFactory $resultPageFactory, 
            \Magento\Framework\App\Request\Http $request, 
            OpenpayPayment $payment,
            \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
            \Magento\Checkout\Model\Session $checkoutSession,
            \Psr\Log\LoggerInterface $logger_interface,
            \Magento\Sales\Model\Service\InvoiceService $invoiceService,
            \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->request = $request;
        $this->payment = $payment;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger_interface;        
        $this->_invoiceService = $invoiceService;
        $this->transactionBuilder = $transactionBuilder;
    }

    /**
     * Load the page defined in view/frontend/layout/openpay_index_webhook.xml
     * URL /openpay/payment/success
     * 
     * @url https://magento.stackexchange.com/questions/197310/magento-2-redirect-to-final-checkout-page-checkout-success-failed?rq=1
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute() {                
        $openpay = $this->payment->getOpenpayInstance();        
        $charge = $openpay->charges->get($this->request->getParam('id'));
        
        $this->logger->debug('#SUCCESS', array('id' => $this->request->getParam('id'), 'status' => $charge->status));
                  
        $order = $this->orderRepository->get($this->checkoutSession->getLastOrderId());
        if ($order && $charge->status != 'completed') {
            $order->cancel();
            $order->addStatusToHistory(\Magento\Sales\Model\Order::STATE_CANCELED, __('Canceled by customer.'));
            $order->save();
                        
            $this->logger->debug('#SUCCESS', array('redirect' => 'checkout/cart'));
            
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');            
        }
                
        $status = \Magento\Sales\Model\Order::STATE_PROCESSING;
        $order->setState($status)->setStatus($status);
        $order->setTotalPaid($charge->amount);  
        $order->addStatusHistoryComment("Pago recibido exitosamente")->setIsCustomerNotified(true);            
        $order->save();        
        
        $invoice = $this->_invoiceService->prepareInvoice($order);        
        $invoice->setTransactionId($charge->id);
        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
        $invoice->register();
        $invoice->save();
                
        $formatedPrice = $order->getBaseCurrency()->formatTxt($order->getGrandTotal());
        
        $payment = $order->getPayment();        
        
        // Prepare transaction
        $transaction = $this->transactionBuilder->setPayment($payment)
            ->setOrder($order)
            ->setTransactionId($charge->id)        
            ->setFailSafe(true)
            ->build(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE);
        $transaction->save();

        // Add transaction to payment
        $payment->addTransactionCommentsToOrder($transaction, __('The authorized amount is %1.', $formatedPrice));
        $payment->setParentTransactionId(null);
        $payment->setAmountPaid($charge->amount);
        $payment->save();
        
        $this->checkoutSession->clearQuote();
        
        $this->logger->debug('#SUCCESS', array('redirect' => 'sales/order/history'));
        return $this->resultRedirectFactory->create()->setPath('sales/order/history');
    }

}
