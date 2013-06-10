<?php
/**
 * (c) 2012 Vespolina Project http://www.vespolina-project.org
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace Vespolina\Payment\StripeBundle\Plugin;

use JMS\Payment\CoreBundle\Plugin\Exception\FinancialException;
use JMS\Payment\CoreBundle\Plugin\Exception\BlockedException;

use JMS\Payment\CoreBundle\Plugin\PluginInterface;

use JMS\Payment\CoreBundle\Plugin\AbstractPlugin;
use JMS\Payment\CoreBundle\Model\CreditCardProfileInterface;
use JMS\Payment\CoreBundle\Model\FinancialTransactionInterface;
use JMS\Payment\CoreBundle\Model\RecurringInstructionInterface;
use JMS\Payment\CoreBundle\Model\RecurringTransactionInterface;

class StripePlugin extends AbstractPlugin
{
    const ED_CARD_TOKEN = 'token';
    const ED_DESCRIPTION = 'description';
    const ED_RESPONSE = 'response';
    
    /** Allows overriding the global private API key, e.g. with an OAuth access token.
     * @see https://stripe.com/docs/connect/oauth#request-api-keys */
    const ED_ACCESS_TOKEN = 'access_token';
    
    /** Allows collecting fees for processing payments on behalf of a 3rd party.
     * @see https://stripe.com/docs/connect/collecting-fees */
    const ED_APPLICATION_FEE = 'application_fee';
    
    protected $apiKey;

    /** @var $logger \Symfony\Bridge\Monolog\Logger */
    private $logger;

    public function __construct($apiKey, \Symfony\Bridge\Monolog\Logger $logger)
    {
        $this->apiKey = $apiKey;
        $this->logger = $logger;
    }

    public function approveAndDeposit(FinancialTransactionInterface $transaction, $retry)
    {
        $chargeArguments = array(
            'amount' => $transaction->getRequestedAmount() * 100,
            'currency' => $transaction->getPayment()->getPaymentInstruction()->getCurrency(),
        );

        $ed = $transaction->getExtendedData();
        if ($ed->has(self::ED_CARD_TOKEN)) {
            $chargeArguments['card'] = $ed->get(self::ED_CARD_TOKEN);
        }
        if ($ed->has(self::ED_DESCRIPTION)) {
            $chargeArguments['description'] = $ed->get(self::ED_DESCRIPTION);
        }
        if ($ed->has(self::ED_APPLICATION_FEE)) {
            $chargeArguments['application_fee'] = $ed->get(self::ED_APPLICATION_FEE);
        }
        $accessToken = null;
        if ($ed->has(self::ED_ACCESS_TOKEN)) {
            $accessToken = $ed->get(self::ED_ACCESS_TOKEN);
        }

        try
        {
            /* @var $response \Stripe_Object */
            $response = $this->sendChargeRequest('create', $chargeArguments, $accessToken);
            
            $processable = $response->__toArray(true);
            
            $transaction->setReferenceNumber($response->__get('id'));
            $transaction->setProcessedAmount($processable['amount']/100);
            
            $ed->set(self::ED_RESPONSE, $processable);
            
            $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
            $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
        }
        catch (\Stripe_CardError $e)
        {
            $body = $e->getJsonBody();
            $err  = $body['error'];
            
            $transaction->setReasonCode($err['code']);
            $transaction->setResponseCode('Failed');
            
            $message = sprintf('Stripe %s: "%s"', $err['type'], $err['code']);
            $ex = new FinancialException($message);
            $ex->setFinancialTransaction($transaction);
            
            $this->logger->err($message);

            throw $ex;
        }
        catch (\Stripe_InvalidRequestError $e)
        {
            $body = $e->getJsonBody();
            $err  = $body['error'];
            
            $transaction->setReasonCode($err['type']);
            $transaction->setResponseCode(PluginInterface::REASON_CODE_INVALID);
            
            $message = sprintf('Stripe %s: "%s"', 
                $err['type'], 
                (key_exists('message', $err) ? $err['message'] : ''));
            $ex = new FinancialException($message);
            $ex->setFinancialTransaction($transaction);
            
            $this->logger->err($message);

            throw $ex;
        }
        catch (\Stripe_AuthenticationError $e)
        {
            $body = $e->getJsonBody();
            $err  = $body['error'];
            
            $transaction->setReasonCode($err['type']);
            $transaction->setResponseCode('Failed');
            
            $message = sprintf('Stripe %s', $err['type']);
            $ex = new FinancialException($message);
            $ex->setFinancialTransaction($transaction);
            
            $this->logger->err($message);

            throw $ex;
        }
        catch (\Stripe_ApiConnectionError $e)
        {
            $body = $e->getJsonBody();
            $err  = $body['error'];
            
            $transaction->setReasonCode($err['type']);
            $transaction->setResponseCode(PluginInterface::REASON_CODE_TIMEOUT);
            
            $message = sprintf('Stripe %s', $err['type']);
            $ex = new BlockedException($message);
            $ex->setFinancialTransaction($transaction);
            
            $this->logger->err($message);

            throw $ex;
        }
        catch (\Stripe_Error $e)
        {
            $transaction->setReasonCode('Stripe_Error');
            $transaction->setResponseCode('Failed');
            
            $message = sprintf('Stripe Stripe_Error');
            $ex = new FinancialException($message);
            $ex->setFinancialTransaction($transaction);
            
            $this->logger->err($message);

            throw $ex;
        }
    }

    public function createPlan(PlanInterface $plan, $retry)
    {
        $arguments = array(
            'id' => $plan->getId(),
            'amount' => $plan->getAmount() * 100,
            'currency' => $plan->getCurrency(),
            'interval' => self::$intervalMapping[$plan->getInterval()],
            'name' => $plan->getName(),
            'trial_period_days' => $plan->getTrialPeriodDays(),
        );

        return $this->sendPlanRequest('create', $arguments);
    }

    public function deletePlan(PlanInterface $plan, $retry)
    {
        $stripePlan = $this->retrievePlan($plan->getId(), $retry);

        return $stripePlan->delete();
    }

    function initializeRecurring(RecurringInstructionInterface $instruction, $retry)
    {
        $creditCardProfile = $instruction->getCreditCardProfile();
        $response = $this->createChargeToken($creditCardProfile);

        $arguments = array(
            'card' => $response['id'],
            'plan' => $instruction->getProviderPlanId(),
            'email' => $creditCardProfile->getEmail()
        );

        $response = $this->sendCustomerRequest('create', $arguments);

        // todo: transaction should be passed in, instructions used to create transaction
        $transaction = new \JMS\Payment\CoreBundle\Document\RecurringTransaction();

        $transaction->setAmount($instruction->getAmount());
        $transaction->setBillingFrequency($instruction->getBillingFrequency());
        $transaction->setBillingInterval($instruction->getBillingInterval());
        $transaction->setCreditCardProfile($creditCardProfile);
        $transaction->setCurrency($instruction->getCurrency());
        $transaction->setPlanId($instruction->getProviderPlanId());
        $transaction->setProcessor('stripe');
        $processable = $response->__toArray(true);
        $transaction->setProcessorId($processable['id']);
        $transaction->addResponseData($processable);

        return $transaction;
    }

    public function processes($paymentSystemName)
    {
        return 'stripe' === $paymentSystemName;
    }

    public function retrievePlan($id, $retry)
    {
        return $this->sendPlanRequest('retrieve', $id);
    }

    public function isIndependentCreditSupported()
    {
        return false; // todo: this needs to be researched
    }

    protected function createChargeToken(CreditCardProfileInterface $creditCard)
    {
        $arguments = array(
            "card" => $this->mapCreditCard($creditCard),
            "currency" => "usd",
        );
        $response = $this->sendTokenRequest('create', $arguments);

        return $response;
    }

    protected function mapCreditCard(CreditCardProfileInterface $creditCard)
    {
        $expiration = $creditCard->getExpiration();

        return array(
            "number" => $creditCard->getCardNumber('active'),
            "exp_month" => $expiration['month'],
            "exp_year" => $expiration['year'],
            "cvc" => $creditCard->getCvv(),
            'name' => $creditCard->getName(),
            'address_line1' => $creditCard->getStreet1(),
            'address_line2' => $creditCard->getStreet2(),
            'address_zip' => $creditCard->getPostcode(),
            'address_state' => $creditCard->getState(),
            'address_country' => $creditCard->getCountry(),
        );
    }

    protected function sendChargeRequest($method, $arguments, $accessToken = null)
    {
        if ($accessToken === null) {
            \Stripe::setApiKey($this->apiKey);
        }
        $response = \Stripe_Charge::$method($arguments, $accessToken);

        return $response;
    }

    protected function sendCustomerRequest($method, $arguments, $accessToken = null)
    {
        if ($accessToken === null) {
            \Stripe::setApiKey($this->apiKey);
        }
        $response = \Stripe_Customer::$method($arguments, $accessToken);

        return $response;
    }

    protected function sendPlanRequest($method, $arguments, $accessToken = null)
    {
        if ($accessToken === null) {
            \Stripe::setApiKey($this->apiKey);
        }
        $response = \Stripe_Plan::$method($arguments, $accessToken);

        return $response;
    }

    protected function sendTokenRequest($method, $arguments, $accessToken = null)
    {
        if ($accessToken === null) {
            \Stripe::setApiKey($this->apiKey);
        }
        $response = \Stripe_Token::$method($arguments, $accessToken);

        return $response;
    }
}
