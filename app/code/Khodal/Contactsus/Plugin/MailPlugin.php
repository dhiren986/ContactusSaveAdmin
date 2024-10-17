<?php

declare(strict_types=1);

namespace Khodal\Contactsus\Plugin;

use Magento\Contact\Model\Mail as ContactMail;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Contact\Model\ConfigInterface;

/**
 * Plugin for customizing the behavior of the Contact Mail class.
 */
class MailPlugin
{
    private const XML_PATH_EMAIL_RECIPIENT = 'contact/email/recipient_email';
    private const XML_PATH_EMAIL_RECIPIENTS = 'contact/email/recipient_emails';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var TransportBuilder
     */
    private $transportBuilder;

    /**
     * @var StateInterface
     */
    private $inlineTranslation;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ConfigInterface
     */
    private $contactsConfig;

    /**
     * MailPlugin constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param TransportBuilder $transportBuilder
     * @param StateInterface $inlineTranslation
     * @param StoreManagerInterface $storeManager
     * @param ConfigInterface $contactsConfig
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        TransportBuilder $transportBuilder,
        StateInterface $inlineTranslation,
        StoreManagerInterface $storeManager,
        ConfigInterface $contactsConfig
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->transportBuilder = $transportBuilder;
        $this->inlineTranslation = $inlineTranslation;
        $this->storeManager = $storeManager;
        $this->contactsConfig = $contactsConfig;
    }

    /**
     * Around plugin for the send method of Contact Mail.
     *
     * @param ContactMail $subject
     * @param callable $proceed
     * @param string $replyTo
     * @param array $variables
     * @return mixed
     */
    public function aroundSend(ContactMail $subject, callable $proceed, string $replyTo, array $variables): mixed
    {
        // Call the original send method first
        $result = $proceed($replyTo, $variables);

        // Retrieve the primary recipient email address
        $recipient = $this->scopeConfig->getValue(self::XML_PATH_EMAIL_RECIPIENT, ScopeInterface::SCOPE_STORE);

        // Handle the primary recipient
        if (!empty($recipient)) {
            $recipient = [$recipient]; // Wrap it in an array
        } else {
            $recipient = []; // Initialize as an empty array if no value
        }

        // Retrieve additional recipient email addresses
        $multiRecipient = $this->scopeConfig->getValue(self::XML_PATH_EMAIL_RECIPIENTS, ScopeInterface::SCOPE_STORE);

        // Initialize $multiRecipients as an empty array
        $multiRecipients = [];

        if (!empty($multiRecipient)) {
            // Split the string into an array and trim each value
            $multiRecipients = array_map('trim', explode(',', $multiRecipient));
        }

        // Combine the primary recipient and additional recipients
        $recipients = array_filter(array_unique(array_merge(
            $recipient,
            $multiRecipients // This should now be an array
        )));
        // Send the email using TransportBuilder
        $this->inlineTranslation->suspend();
        try {
            $this->transportBuilder
                ->setTemplateIdentifier($this->contactsConfig->emailTemplate())
                ->setTemplateOptions(
                    [
                        'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                        'store' => $this->storeManager->getStore()->getId()
                    ]
                )
                ->setTemplateVars($variables)
                ->setFrom($this->contactsConfig->emailSender())
                ->addTo($recipients) // Add merged recipients here
                ->setReplyTo($replyTo, $variables['data']['name'] ?? null)
                ->getTransport()
                ->sendMessage();
        } finally {
            $this->inlineTranslation->resume();
        }

        return $result;
    }
}
