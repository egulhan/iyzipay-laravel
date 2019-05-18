<?php

namespace Iyzico\IyzipayLaravel;

use Iyzico\IyzipayLaravel\Exceptions\Card\PayableMustHaveCreditCardException;
use Iyzico\IyzipayLaravel\Exceptions\Fields\BillFieldsException;
use Iyzico\IyzipayLaravel\Exceptions\Card\CardRemoveException;
use Iyzico\IyzipayLaravel\Exceptions\Fields\CreditCardFieldsException;
use Iyzico\IyzipayLaravel\Exceptions\Transaction\ThreedsTransactionException;
use Iyzico\IyzipayLaravel\Exceptions\Transaction\TransactionSaveException;
use Iyzico\IyzipayLaravel\Exceptions\Transaction\TransactionVoidException;
use Iyzico\IyzipayLaravel\Exceptions\Iyzipay\IyzipayAuthenticationException;
use Iyzico\IyzipayLaravel\Exceptions\Iyzipay\IyzipayConnectionException;
use Iyzico\IyzipayLaravel\Models\CreditCard;
use Iyzico\IyzipayLaravel\Models\PaymentLog;
use Iyzico\IyzipayLaravel\Models\ThreedsPaymentStepLog;
use Iyzico\IyzipayLaravel\Models\Transaction;
use Iyzico\IyzipayLaravel\Traits\ManagesPlans;
use Iyzico\IyzipayLaravel\Traits\PreparesCreditCardRequest;
use Iyzico\IyzipayLaravel\Traits\PreparesTransactionRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Iyzipay\Model\ApiTest;
use Iyzipay\Model\Payment;
use Iyzipay\Options;
use Iyzipay\Model\Locale;
use Iyzico\IyzipayLaravel\PayableContract as Payable;
use Iyzipay\Model\ThreedsInitialize;

class IyzipayLaravel
{

    use PreparesCreditCardRequest, PreparesTransactionRequest, ManagesPlans;

    /**
     * @var Options
     */
    protected $apiOptions;
    /**
     * @var $threedsCallbackUrl
     */
    protected $threedsCallbackUrl;

    public function __construct()
    {
        $this->initializeApiOptions();
        $this->checkApiOptions();

        // 3D secure callback URL
        $this->setThreedsCallbackUrl(config('iyzipay.threedsCallbackUrl'));
    }

    /**
     * Adds credit card for billable & payable model.
     *
     * @param PayableContract $payable
     * @param array $attributes
     *
     * @return CreditCard
     * @throws BillFieldsException
     * @throws CreditCardFieldsException
     */
    public function addCreditCard(Payable $payable, array $attributes = []): CreditCard
    {
        $this->validateBillable($payable);
        $this->validateCreditCardAttributes($attributes);

        $card = $this->createCardOnIyzipay($payable, $attributes);

        $creditCardModel = new CreditCard([
            'alias' => $card->getCardAlias(),
            'number' => $card->getBinNumber(),
            'token' => $card->getCardToken(),
            'bank' => $card->getCardBankName()
        ]);
        $payable->creditCards()->save($creditCardModel);

        $payable->iyzipay_key = $card->getCardUserKey();
        $payable->save();

        return $creditCardModel;
    }

    /**
     * Remove credit card for billable & payable model.
     *
     * @param CreditCard $creditCard
     *
     * @return bool
     * @throws CardRemoveException
     */
    public function removeCreditCard(CreditCard $creditCard): bool
    {
        $this->removeCardOnIyzipay($creditCard);
        $creditCard->delete();

        return true;
    }

    /**
     * @param PayableContract $payable
     * @param Collection $products
     * @param $currency
     * @param $installment
     * @param bool $subscription
     * @param null $creditCard
     * @param bool $isThreeds
     * @return Transaction|ThreedsInitialize
     * @throws BillFieldsException
     * @throws PayableMustHaveCreditCardException
     * @throws TransactionSaveException
     */
    public function singlePayment(Payable $payable, Collection $products, $currency, $installment, $subscription = false, $creditCard = null, $isThreeds = false)
    {
        $conversationId = null;
        $paymentMethod = $isThreeds ? 'initializeThreedsOnIyzipay' : 'createTransactionOnIyzipay';

        if (!isset($creditCard)) {
            $this->validateBillable($payable);
            $this->validateHasCreditCard($payable);

            $messages = []; // @todo imporove here
            foreach ($payable->creditCards as $creditCard) {
                $conversationId = time();

                // log payment request
                $paymentLog = $this->logPayment(
                    $conversationId, $payable, $products, $currency, $installment,
                    $subscription, $creditCard->number, $isThreeds
                );
                $threedsPaymentStepLog = $this->logThreedsPaymentStep(
                    ThreedsPaymentStepLog::STEP_INIT, null, null, $paymentLog
                );

                try {
                    $result = $this->$paymentMethod(
                        $payable,
                        $creditCard,
                        compact('products', 'currency', 'installment'),
                        $subscription,
                        $conversationId
                    );

                    if ($isThreeds) {
                        return $result;
                    }

                    return $this->storeTransactionModel($result, $payable, $products, $creditCard);
                } catch (TransactionSaveException $e) {
                    $paymentLog->error_message = $e->getMessage();
                    $paymentLog->save();

                    $messages[] = $creditCard->number . ': ' . $e->getMessage();
                    continue;
                } catch (ThreedsTransactionException $e) {
                    $threedsPaymentStepLog->error_message = $e->getMessage();
                    $threedsPaymentStepLog->save();

                    $messages[] = $creditCard->number . ': ' . $e->getMessage();
                    continue;
                }
            }
        } else {
            $conversationId = time();

            // log payment request
            $paymentLog = $this->logPayment($conversationId, $payable, $products, $currency, $installment, $subscription,
                $this->extractCardBinNumberFromCardNumber($creditCard['cardNumber']), $isThreeds);
            $threedsPaymentStepLog = $this->logThreedsPaymentStep(
                ThreedsPaymentStepLog::STEP_INIT, null, null, $paymentLog
            );

            try {
                $result = $this->$paymentMethod(
                    $payable,
                    $creditCard,
                    compact('products', 'currency', 'installment'),
                    $subscription,
                    $conversationId
                );

                if ($isThreeds) {
                    return $result;
                }

                return $this->storeTransactionModel($result, $payable, $products, $creditCard);
            } catch (TransactionSaveException $e) {
                $paymentLog->error_message = $e->getMessage();
                $paymentLog->save();

                $messages[] = $creditCard['cardNumber'] . ': ' . $e->getMessage();
            } catch (ThreedsTransactionException $e) {
                $threedsPaymentStepLog->error_message = $e->getMessage();
                $threedsPaymentStepLog->save();

                $messages[] = $creditCard['cardNumber'] . ': ' . $e->getMessage();
            }
        }

        // TODO: should change this if it is 3D secure payment
        throw new TransactionSaveException(implode(', ', $messages));
    }

    /**
     * Handles 3D secure payment callback request
     * @param $status
     * @param $paymentId
     * @param $conversationData
     * @param $conversationId
     * @param $mdStatus
     * @param PayableContract $payable
     * @param Collection $products
     * @param null $creditCard
     * @return mixed
     * @throws TransactionSaveException
     */
    public function handleThreedsPaymentCallbackRequest(
        $status, $paymentId, $conversationData, $conversationId, $mdStatus,
        Payable $payable, Collection $products, $creditCard
    )
    {
        // save log
        $paymentLog = PaymentLog::where('conversation_id', $conversationId)->first();
        $threedsPaymentStepLog = new ThreedsPaymentStepLog([
            'result' => $status == 'success' ? 1 : 0,
            'step' => ThreedsPaymentStepLog::STEP_REQUESTED_CALLBACK_URL,
            'payload' => compact('status', 'paymentId', 'conversationData', 'conversationId', 'mdStatus'),
        ]);
        $paymentLog->addThreedPaymentStepLog($threedsPaymentStepLog);

        if ($status == 'success') {
            $threedsPaymentStepLog = new ThreedsPaymentStepLog([
                'step' => ThreedsPaymentStepLog::STEP_PAY_WITH_THREEDS,
                'payload' => compact('paymentId', 'conversationData'),
            ]);
            $paymentLog->addThreedPaymentStepLog($threedsPaymentStepLog);

            try {
                $payment = $this->payWithThreeds($paymentId, $conversationData, $conversationId);

                $threedsPaymentStepLog->result = 1;
                $threedsPaymentStepLog->save();
                $paymentLog->result = 1;
                $paymentLog->save();

                return $this->storeTransactionModel($payment, $payable, $products, $creditCard);
            } catch (ThreedsTransactionException $e) {
                $threedsPaymentStepLog->error_message = $e->getMessage();
                $threedsPaymentStepLog->save();

                throw new TransactionSaveException($e->getMessage());
            }
        } else {
            throw new TransactionSaveException(__('threeds_payment_callback_request_mdstatus.' . $mdStatus));
        }
    }

    /**
     * @param $cardNumber
     * @return bool|string
     */
    private function extractCardBinNumberFromCardNumber($cardNumber)
    {
        return substr($cardNumber, 0, 6);
    }

    /**
     * @param Transaction $transactionModel
     *
     * @return Transaction
     * @throws TransactionVoidException
     */
    public function void(Transaction $transactionModel): Transaction
    {
        $cancel = $this->createCancelOnIyzipay($transactionModel);

        $transactionModel->voided_at = Carbon::now();
        $refunds = $transactionModel->refunds;
        $refunds[] = [
            'type' => 'void',
            'amount' => $cancel->getPrice(),
            'iyzipay_key' => $cancel->getPaymentId()
        ];

        $transactionModel->refunds = $refunds;
        $transactionModel->save();

        return $transactionModel;
    }

    /**
     * Initializing API options with the given credentials.
     */
    private function initializeApiOptions()
    {
        $this->apiOptions = new Options();
        $this->apiOptions->setBaseUrl(config('iyzipay.baseUrl'));
        $this->apiOptions->setApiKey(config('iyzipay.apiKey'));
        $this->apiOptions->setSecretKey(config('iyzipay.secretKey'));
    }

    /**
     * Check if api options has been configured successfully.
     *
     * @throws IyzipayAuthenticationException
     * @throws IyzipayConnectionException
     */
    private function checkApiOptions()
    {
        try {
            $check = ApiTest::retrieve($this->apiOptions);
        } catch (\Exception $e) {
            throw new IyzipayConnectionException();
        }

        if ($check->getStatus() != 'success') {
            throw new IyzipayAuthenticationException();
        }
    }

    /**
     * @param PayableContract $payable
     *
     * @throws BillFieldsException
     */
    private function validateBillable(Payable $payable): void
    {
        if (!$payable->isBillable()) {
            throw new BillFieldsException();
        }
    }

    /**
     * @param PayableContract $payable
     *
     * @throws PayableMustHaveCreditCardException
     */
    private function validateHasCreditCard(Payable $payable): void
    {
        if ($payable->creditCards->isEmpty()) {
            throw new PayableMustHaveCreditCardException();
        }
    }

    /**
     * @param Payment $transaction
     * @param PayableContract $payable
     * @param Collection $products
     * @param $creditCard
     * @return Transaction
     */
    private function storeTransactionModel(
        Payment $transaction,
        Payable $payable,
        Collection $products,
        $creditCard
    ): Transaction
    {
        $iyzipayProducts = [];
        foreach ($transaction->getPaymentItems() as $paymentItem) {
            $iyzipayProducts[] = [
                'iyzipay_key' => $paymentItem->getPaymentTransactionId(),
                'paidPrice' => $paymentItem->getPaidPrice(),
                'product' => $products->where(
                    $products[0]->getKeyName(),
                    $paymentItem->getItemId()
                )->first()->toArray()
            ];
        }

        $transactionModel = new Transaction([
            'amount' => $transaction->getPaidPrice(),
            'products' => $iyzipayProducts,
            'iyzipay_key' => $transaction->getPaymentId(),
            'currency' => $transaction->getCurrency()
        ]);

        if ($creditCard instanceof CreditCard) {
            $transactionModel->creditCard()->associate($creditCard);
        } else {
            $creditCardBinNumber = substr($creditCard['cardNumber'], 0, 6);
            $transactionModel->credit_card_number = $creditCardBinNumber;
        }

        $payable->transactions()->save($transactionModel);

        return $transactionModel->fresh();
    }

    /**
     * Logs a payment
     * @param $conversationId
     * @param $payable
     * @param $products
     * @param $currency
     * @param $installment
     * @param $subscription
     * @param $creditCardBinNumber
     * @param bool $isThreeds
     * @return PaymentLog
     */
    private function logPayment($conversationId, $payable, $products, $currency, $installment, $subscription, $creditCardBinNumber, $isThreeds = false)
    {
        $log = new PaymentLog([
            'conversation_id' => $conversationId,
            'billable_id' => $payable->id,
            'is_threeds' => (int)$isThreeds,
            'products' => $products->toArray(),
            'currency' => $currency,
            'installment' => $installment,
            'subscription' => (int)$subscription,
            'credit_card_number' => $creditCardBinNumber,
        ]);

        $log->save();

        return $log;
    }

    /**
     * Logs a 3D secure payment step
     * @param $step
     * @param null $payload
     * @param null $conversationId
     * @param PaymentLog|null $paymentLog
     * @return null
     */
    private function logThreedsPaymentStep($step, $payload = null, $conversationId = null, PaymentLog $paymentLog = null)
    {
        if (!$paymentLog) {
            $paymentLog = PaymentLog::where('conversation_id', $conversationId)->first();
        }

        if ($paymentLog) {
            $threedPaymentStepLog = new ThreedsPaymentStepLog([
                'step' => $step,
                'payload' => $payload
            ]);

            return $paymentLog->addThreedsPaymentStepLog($threedPaymentStepLog);
        }

        return null;
    }

    protected function getLocale(): string
    {
        return (config('app.locale') == 'tr') ? Locale::TR : Locale::EN;
    }

    protected function getOptions(): Options
    {
        return $this->apiOptions;
    }

    /**
     * @return string
     */
    public function getThreedsCallbackUrl(): string
    {
        return $this->threedsCallbackUrl;
    }

    /**
     * @param mixed $threedsCallbackUrl
     */
    public function setThreedsCallbackUrl($threedsCallbackUrl)
    {
        $this->threedsCallbackUrl = $threedsCallbackUrl;
    }
}
