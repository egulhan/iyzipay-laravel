<?php

namespace Iyzico\IyzipayLaravel\Traits;

use Iyzico\IyzipayLaravel\Exceptions\Fields\TransactionFieldsException;
use Iyzico\IyzipayLaravel\Exceptions\Transaction\ThreedsTransactionException;
use Iyzico\IyzipayLaravel\Exceptions\Transaction\TransactionSaveException;
use Iyzico\IyzipayLaravel\Exceptions\Transaction\TransactionVoidException;
use Iyzico\IyzipayLaravel\Models\CreditCard;
use Iyzico\IyzipayLaravel\Models\ThreedsPaymentStepLog;
use Iyzico\IyzipayLaravel\Models\Transaction;
use Iyzico\IyzipayLaravel\PayableContract as Payable;
use Iyzico\IyzipayLaravel\ProductContract;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Iyzipay\Model\Address;
use Iyzipay\Model\BasketItem;
use Iyzipay\Model\Buyer;
use Iyzipay\Model\Cancel;
use Iyzipay\Model\Currency;
use Iyzipay\Model\Payment;
use Iyzipay\Model\PaymentCard;
use Iyzipay\Model\PaymentChannel;
use Iyzipay\Model\PaymentGroup;
use Iyzipay\Options;
use Iyzipay\Request\CreateCancelRequest;
use Iyzipay\Request\CreatePaymentRequest;
use Iyzipay\Model\ThreedsInitialize;
use Iyzipay\Model\ThreedsPayment;

trait PreparesTransactionRequest
{

    /**
     * Validation for the transaction
     *
     * @param $attributes
     */
    protected function validateTransactionFields($attributes): void
    {
        $totalPrice = 0;
        foreach ($attributes['products'] as $product) {
            if (!$product instanceof ProductContract) {
                throw new TransactionFieldsException();
            }
            $totalPrice += $product->getPrice();
        }

        $v = Validator::make($attributes, [
            'installment' => 'required|numeric|min:1',
            'currency' => 'required|in:' . implode(',', [
                    Currency::TL,
                    Currency::EUR,
                    Currency::GBP,
                    Currency::IRR,
                    Currency::USD
                ]),
            'paid_price' => 'numeric|max:' . $totalPrice
        ]);

        if ($v->fails()) {
            throw new TransactionFieldsException();
        }
    }

    /**
     * Creates transaction on iyzipay.
     *
     * @param Payable $payable
     * @param $creditCard
     * @param array $attributes
     * @param bool $subscription
     *
     * @param null $conversationId
     * @return Payment
     * @throws TransactionFieldsException
     * @throws TransactionSaveException
     */
    protected function createTransactionOnIyzipay(
        Payable $payable,
        $creditCard,
        array $attributes,
        $subscription = false,
        $conversationId = null
    ): Payment
    {
        $this->validateTransactionFields($attributes);
        $paymentRequest = $this->createPaymentRequest($attributes, $subscription, false, $conversationId);
        $paymentRequest->setPaymentCard($this->preparePaymentCard($payable, $creditCard));
        $paymentRequest->setBuyer($this->prepareBuyer($payable));
        $paymentRequest->setShippingAddress($this->prepareAddress($payable, 'shippingAddress'));
        $paymentRequest->setBillingAddress($this->prepareAddress($payable, 'billingAddress'));
        $paymentRequest->setBasketItems($this->prepareBasketItems($attributes['products']));

        try {
            $payment = Payment::create($paymentRequest, $this->getOptions());
        } catch (\Exception $e) {
            throw new TransactionSaveException();
        }

        unset($paymentRequest);

        if ($payment->getStatus() != 'success') {
            throw new TransactionSaveException($payment->getErrorMessage(), $payment->getConversationId());
        }

        return $payment;
    }

    /**
     * Initializes 3D secure on iyzipay.
     *
     * @param Payable $payable
     * @param $creditCard
     * @param array $attributes
     * @param bool $subscription
     *
     * @param null $conversationId
     * @return Payment
     * @throws ThreedsTransactionException
     * @throws TransactionFieldsException
     * @throws TransactionSaveException
     */
    protected function initializeThreedsOnIyzipay(
        Payable $payable,
        $creditCard,
        array $attributes,
        $subscription = false,
        $conversationId = null
    ): ThreedsInitialize
    {
        $this->validateTransactionFields($attributes);
        $paymentRequest = $this->createPaymentRequest($attributes, $subscription, true, $conversationId);
        $paymentRequest->setPaymentCard($this->preparePaymentCard($payable, $creditCard));
        $paymentRequest->setBuyer($this->prepareBuyer($payable));
        $paymentRequest->setShippingAddress($this->prepareAddress($payable, 'shippingAddress'));
        $paymentRequest->setBillingAddress($this->prepareAddress($payable, 'billingAddress'));
        $paymentRequest->setBasketItems($this->prepareBasketItems($attributes['products']));

        try {
            $threedsInit = ThreedsInitialize::create($paymentRequest, $this->getOptions());
        } catch (\Exception $e) {
            throw new ThreedsTransactionException('can not initialize 3D secure payment',
                $conversationId, ThreedsPaymentStepLog::STEP_INIT);
        }

        unset($paymentRequest);

        if ($threedsInit->getStatus() != 'success') {
            throw new ThreedsTransactionException($threedsInit->getErrorMessage(),
                $threedsInit->getConversationId(), ThreedsPaymentStepLog::STEP_INIT);
        }

        return $threedsInit;
    }

    /**
     * Pays with 3D secure (this should must called after initializeThreedsOnIyzipay)
     * @param $paymentId
     * @param $conversationData
     * @param $conversationId
     * @return ThreedsPayment $payment
     * @throws ThreedsTransactionException
     */
    protected function payWithThreeds($paymentId, $conversationData, $conversationId)
    {
        $paymentRequest = $this->createThreedsPaymentRequest($paymentId, $conversationData);

        try {
            $payment = ThreedsPayment($paymentRequest, $this->getOptions());

            if ($payment->getStatus() != 'success') {
                throw new \Exception($payment->getErrorMessage());
            }
        } catch (\Exception $e) {
            throw new ThreedsTransactionException($e->getMessage(),
                $conversationId, ThreedsPaymentStepLog::STEP_PAY_WITH_THREEDS);
        }

        return $payment;
    }

    /**
     * @param Transaction $transaction
     *
     * @return Cancel
     * @throws TransactionVoidException
     */
    protected function createCancelOnIyzipay(Transaction $transaction): Cancel
    {
        $cancelRequest = $this->prepareCancelRequest($transaction->iyzipay_key);

        try {
            $cancel = Cancel::create($cancelRequest, $this->getOptions());
        } catch (\Exception $e) {
            throw new TransactionVoidException();
        }

        unset($cancelRequest);

        if ($cancel->getStatus() != 'success') {
            throw new TransactionVoidException($cancel->getErrorMessage());
        }

        return $cancel;
    }

    /**
     * Prepares create payment request class for iyzipay.
     *
     * @param array $attributes
     * @param bool $subscription
     * @param bool $threeds
     * @param null $conversationId
     * @return CreatePaymentRequest
     */
    private function createPaymentRequest(array $attributes, $subscription = false, $threeds = false, $conversationId = null): CreatePaymentRequest
    {
        $paymentRequest = new CreatePaymentRequest();
        $paymentRequest->setLocale($this->getLocale());

        $totalPrice = 0;
        foreach ($attributes['products'] as $product) {
            $totalPrice += $product->getPrice();
        }

        $paymentRequest->setPrice($totalPrice);
        $paymentRequest->setPaidPrice($totalPrice); // @todo this may change
        $paymentRequest->setCurrency($attributes['currency']);
        $paymentRequest->setInstallment($attributes['installment']);
        $paymentRequest->setPaymentChannel(PaymentChannel::WEB);
        $paymentRequest->setPaymentGroup(($subscription) ? PaymentGroup::SUBSCRIPTION : PaymentGroup::PRODUCT);

        if ($threeds) {
            $paymentRequest->setCallbackUrl($this->getThreedsCallbackUrl());
        }

        if (isset($conversationId)) {
            $paymentRequest->setConversationId($conversationId);
        }

        return $paymentRequest;
    }

    /**
     * Prepares cancel request class for iyzipay
     *
     * @param $iyzipayKey
     * @return CreateCancelRequest
     */
    private function prepareCancelRequest($iyzipayKey): CreateCancelRequest
    {
        $cancelRequest = new CreateCancelRequest();
        $cancelRequest->setPaymentId($iyzipayKey);
        $cancelRequest->setIp(request()->ip());
        $cancelRequest->setLocale($this->getLocale());

        return $cancelRequest;
    }

    /**
     * Prepares payment card class for iyzipay
     *
     * @param Payable $payable
     * @param $creditCard
     * @return PaymentCard
     */
    private function preparePaymentCard(Payable $payable, $creditCard): PaymentCard
    {
        $paymentCard = new PaymentCard();

        if ($creditCard instanceof CreditCard) {
            $paymentCard->setCardUserKey($payable->iyzipay_key);
            $paymentCard->setCardToken($creditCard->token);
        } else {
            $paymentCard->setCardHolderName($creditCard['cardHolderName']);
            $paymentCard->setCardNumber($creditCard['cardNumber']);
            $paymentCard->setExpireMonth($creditCard['expireMonth']);
            $paymentCard->setExpireYear($creditCard['expireYear']);
            $paymentCard->setCvc($creditCard['cvc']);
            $paymentCard->setRegisterCard(0);
        }

        return $paymentCard;
    }

    /**
     * Prepares buyer class for iyzipay
     *
     * @param Payable $payable
     * @return Buyer
     */
    private function prepareBuyer(Payable $payable): Buyer
    {
        $buyer = new Buyer();
        $buyer->setId($payable->getKey());

        $billFields = $payable->bill_fields;
        $buyer->setName($billFields->firstName);
        $buyer->setSurname($billFields->lastName);
        $buyer->setEmail($billFields->email);
        $buyer->setGsmNumber($billFields->mobileNumber);
        $buyer->setIdentityNumber($billFields->identityNumber);
        $buyer->setCity($billFields->billingAddress->city);
        $buyer->setCountry($billFields->billingAddress->country);
        $buyer->setRegistrationAddress($billFields->billingAddress->address);

        return $buyer;
    }

    /**
     * Prepares address class for iyzipay.
     *
     * @param Payable $payable
     * @param string $type
     * @return Address
     */
    private function prepareAddress(Payable $payable, $type = 'shippingAddress'): Address
    {
        $address = new Address();

        $billFields = $payable->bill_fields;
        $address->setContactName($billFields->firstName . ' ' . $billFields->lastName);
        $address->setCountry($billFields->$type->country);
        $address->setAddress($billFields->$type->address);
        $address->setCity($billFields->$type->city);

        return $address;
    }

    /**
     * Prepares basket items class for iyzipay.
     *
     * @param Collection $products
     * @return array
     */
    private function prepareBasketItems(Collection $products): array
    {
        $basketItems = [];

        foreach ($products as $product) {
            $item = new BasketItem();
            $item->setId($product->getKey());
            $item->setName($product->getName());
            $item->setCategory1($product->getCategory());
            $item->setPrice($product->getPrice());
            $item->setItemType($product->getType());
            $basketItems[] = $item;
        }

        return $basketItems;
    }

    abstract protected function getLocale(): string;

    abstract protected function getOptions(): Options;
}
