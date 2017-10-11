<?php

namespace Drupal\gsa_feed\Service;

use DateTime;
use DOMImplementation;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class FeedClient.
 *
 * @see: https://support.google.com/gsa/answer/6329211#74620
 *
 * @package Drupal\gsa_feed\Service
 */
class FeedClient {

  use StringTranslationTrait;

  /**
   * Feedtype constant: full.
   *
   * When the feedtype element is set to full for a content feed,
   * the system deletes all the prior URLs that were associated with
   * the data source. The new feed contents completely replace the prior
   * feed contents. If the feed contains metadata, you must also provide
   * content for each record; a full feed cannot push metadata alone.
   * You can delete all documents in a data source
   * by pushing an empty full feed.
   */
  const FEED_TYPE_FULL = 'full';

  /**
   * Feedtype constant: incremental.
   *
   * When the feedtype element is set to incremental, the system modifies
   * the URLs that exist in the new feed as specified by the action attribute
   * for the record. URLs from previous feeds remain associated with the
   * content data source. If the record contains metadata,
   * you can incrementally update either the content or the metadata.
   */
  const FEED_TYPE_INCREMENTAL = 'incremental';

  /**
   * Feedtype constant: metadata-and-url.
   *
   * When the feedtype element is set to metadata-and-url,
   * the system modifies the URLs and metadata that exist in the new feed
   * as specified by the action attribute for the record.
   * URLs and metadata from previous feeds remain associated
   * with the content data source. You can use this feed type
   * even if you do not define any metadata in the feed.
   * The system treats any data source with this feed type
   * as a special kind of web feed and updates the feed incrementally.
   * Unless the metadata-and-url feed has the crawl-immediately=true directive
   * the search appliance will schedule the re-crawling of the URL
   * instead of re-crawling it without delay.
   */
  const FEED_TYPE_METADATA_AND_URL = 'metadata-and-url';

  const FEED_ACTION_ADD = 'add';
  const FEED_ACTION_DELETE = 'delete';
  const FEED_SOURCE_WEB = 'web';

  const GSA_FEED_LOG_CHANNEL = 'gsa_feed';
  const GSA_FEED_CONFIG_KEY = 'gsa_feed.settings';

  /**
   * Format for the update time., ISO-8601.
   *
   * It should output e.g 2017-08-16T10:23:51Z .
   */
  const UPDATE_DATETIME_FORMAT = DateTime::ATOM;

  /**
   * The HTTP Client.
   *
   * @var \GuzzleHttp\Client
   */
  private $httpClient;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private $logger;

  /**
   * The full URL of the GSA device feed.
   *
   * @var string
   *
   * @example http://example.com:19900/xmlfeed
   */
  private $gsaUrl;

  /**
   * The GSA admin username.
   *
   * @var string
   */
  private $gsaUser;

  /**
   * The GSA admin password.
   *
   * @var string
   */
  private $gsaPassword;

  /**
   * The full URL to the gsafeed.dtd file.
   *
   * @var string
   *
   * @example http://example.com:7800/gsafeed.dtd
   */
  private $gsaXMLSystemId;

  /**
   * An array of content type IDs.
   *
   * @var array
   */
  private $gsaContentTypeWhitelist;

  /**
   * The current HTTP host.
   *
   * @var string
   */
  private $httpHost;

  /**
   * The feed type.
   *
   * Can only be set to one of these:
   *   FeedClient::FEED_TYPE_FULL
   *   FeedClient::FEED_TYPE_INCREMENTAL
   *   FeedClient::FEED_TYPE_METADATA_AND_URL
   * Other options throw an exception.
   *
   * @var string
   */
  private $feedType = self::FEED_TYPE_INCREMENTAL;

  /**
   * The feed data source.
   *
   * @var string
   */
  private $feedDataSource = self::FEED_SOURCE_WEB;

  /**
   * FeedClient constructor.
   *
   * @param \GuzzleHttp\Client $client
   *   HTTP Client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   Logger factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    Client $client,
    ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
    RequestStack $requestStack
  ) {
    $this->httpClient = $client;
    $this->logger = $loggerFactory->get(self::GSA_FEED_LOG_CHANNEL);
    $conf = $configFactory->get(self::GSA_FEED_CONFIG_KEY);

    $this->gsaUser = $conf->get('gsa_admin_user');
    $this->gsaPassword = $conf->get('gsa_admin_password');
    $this->gsaUrl = 'http://' . $conf->get('gsa_host') . ':19900/xmlfeed';
    $this->gsaXMLSystemId = 'http://' . $conf->get('gsa_host') . ':7800/gsafeed.dtd';
    $this->gsaContentTypeWhitelist = $conf->get('gsa_node_type_whitelist');
    $this->httpHost = $requestStack->getCurrentRequest()->getSchemeAndHttpHost();
  }

  /**
   * Get the feed data source.
   *
   * @return string
   *   The feed data source.
   */
  public function getFeedDataSource() {
    return $this->feedDataSource;
  }

  /**
   * Set the feed data source.
   *
   * @param string $feedDataSource
   *   The feed data source.
   *
   * @return \Drupal\gsa_feed\Service\FeedClient
   *   The class for chaining.
   */
  public function setFeedDataSource($feedDataSource): FeedClient {
    $this->feedDataSource = $feedDataSource;
    return $this;
  }

  /**
   * Get the feed type.
   *
   * @return string
   *   The feed type.
   */
  public function getFeedType() {
    return $this->feedType;
  }

  /**
   * Set the feed type.
   *
   * @param string $feedType
   *   The feed type.
   *
   * @return \Drupal\gsa_feed\Service\FeedClient
   *   The class for chaining.
   *
   * @throws \InvalidArgumentException
   */
  public function setFeedType($feedType): FeedClient {
    if (!in_array($feedType, [
      self::FEED_TYPE_FULL,
      self::FEED_TYPE_INCREMENTAL,
      self::FEED_TYPE_METADATA_AND_URL,
    ], TRUE)
    ) {
      throw new \InvalidArgumentException("The given feed type '$feedType' is invalid.'");
    }

    $this->feedType = $feedType;
    return $this;
  }

  /**
   * Handler function to push entities to the GSA device.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to be pushed.
   * @param string $action
   *   Either FeedClient::FEED_ACTION_ADD or FeedClient::FEED_ACTION_DELETE.
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Drupal\Core\Entity\Exception\UndefinedLinkTemplateException
   * @throws \InvalidArgumentException
   * @throws \UnexpectedValueException
   */
  public function pushHandler(EntityInterface $entity, $action) {
    // @todo: Maybe make some of these configurable.
    if ($entity->getEntityTypeId() !== 'node') {
      return;
    }

    if (!is_array($this->gsaContentTypeWhitelist) || empty($this->gsaContentTypeWhitelist)) {
      return;
    }

    /** @var \Drupal\node\NodeInterface $entity */
    $entityBundleAllowed = in_array(
      $entity->getType(),
      $this->gsaContentTypeWhitelist,
      TRUE
    );

    if (TRUE === $entityBundleAllowed) {
      $this->pushEntity($entity, $action);
    }
  }

  /**
   * Push a single entity to the GSA device.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to be pushed.
   * @param string $action
   *   The action, either 'add' or 'delete'.
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Drupal\Core\Entity\Exception\UndefinedLinkTemplateException
   * @throws \UnexpectedValueException
   * @throws \InvalidArgumentException
   */
  public function pushEntity(
    EntityInterface $entity,
    $action = self::FEED_ACTION_ADD
  ) {
    $data = $this->createFeed([$entity], $action);
    $this->push($data);
  }

  /**
   * Push multiple entities to the GSA device.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   The array of entities to be pushed.
   * @param string $action
   *   The action, either 'add' or 'delete'.
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Drupal\Core\Entity\Exception\UndefinedLinkTemplateException
   * @throws \UnexpectedValueException
   * @throws \InvalidArgumentException
   */
  public function pushMultipleEntities(array $entities, $action = self::FEED_ACTION_ADD) {
    $data = $this->createFeed($entities, $action);
    $this->push($data);
  }

  /**
   * Push data to the GSA device.
   *
   * @param string $data
   *   The XML data to be pushed.
   */
  public function push($data) {
//    $this->logger->debug('Trying to push data to the GSA device: @data', [
//      '@data' => $data,
//    ]);

    // @see: https://stackoverflow.com/questions/26021488/submitting-a-google-search-appliance-gsa-content-feed-with-php-curl-400-erro
    $fields = [
      [
        'name' => 'feedtype',
        'contents' => $this->feedType,
      ],
      [
        'name' => 'datasource',
        'contents' => $this->feedDataSource,
      ],
      [
        'name' => 'data',
        'contents' => $data,
      ],
    ];

    $options = [
      RequestOptions::HTTP_ERRORS => TRUE,
      RequestOptions::TIMEOUT => 120,
      RequestOptions::CONNECT_TIMEOUT => 10,
      RequestOptions::MULTIPART => $fields,
    ];

    // Set auth data only if it's provided.
    if (!(empty($this->gsaUser) || empty($this->gsaPassword))) {
      $options[RequestOptions::AUTH] = [
        $this->gsaUser,
        $this->gsaPassword,
      ];
    }

    try {
      $response = $this->httpClient->post($this->gsaUrl, $options);
//      drupal_set_message($this->t('Response: @code, @content', [
//        '@code' => $response->getStatusCode(),
//        '@content' => $response->getBody()->getContents(),
//      ]));

      $pushResult = [
        'responseCode' => $response->getStatusCode(),
        'responseMessage' => $response->getBody()->getContents(),
      ];
      $this->logger->info(var_export($pushResult, TRUE));
    }
    catch (\Exception $exception) {
      $this->logger->error('GSA Feed Exception: ' . $exception->getMessage());
    }
  }

  /**
   * Creates an XML from entities and returns it as a string.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   The array of entities to be added to the XML.
   * @param string $action
   *   The action, either 'add' or 'delete'.
   *
   * @return string
   *   The XML string.
   *
   * @throws \UnexpectedValueException
   * @throws \Drupal\Core\Entity\Exception\UndefinedLinkTemplateException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \InvalidArgumentException
   *
   * @todo: Maybe refactor.
   */
  public function createFeed(array $entities, $action = self::FEED_ACTION_ADD) {
    $root = 'gsafeed';
    $creator = new DOMImplementation();
    $doctype = $creator->createDocumentType(
      $root,
      '-//Google//DTD GSA Feeds//EN',
      $this->gsaXMLSystemId
    );
    $xml = $creator->createDocument(NULL, NULL, $doctype);
    $xml->encoding = 'utf-8';
    $xml->formatOutput = TRUE;

    $xmlHeader = $xml->createElement('header');
    $xmlHeader->appendChild($xml->createElement('datasource', $this->feedDataSource));
    $xmlHeader->appendChild($xml->createElement('feedtype', $this->feedType));

    $xmlGroup = $xml->createElement('group');

    foreach ($entities as $entity) {
      $xmlGroup->appendChild($this->createEntityRecord($xml, $entity, $action));
    }

    $xmlRoot = $xml->createElement($root);
    $xmlRoot->appendChild($xmlHeader);
    $xmlRoot->appendChild($xmlGroup);

    $xml->appendChild($xmlRoot);

    return $xml->saveXML();
  }

  /**
   * Turn an entity into an XML record.
   *
   * @param \DOMDocument $xml
   *   The XML instance.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to be added.
   * @param string $action
   *   The action, eihter 'add' or 'delete'.
   *
   * @return \DOMElement
   *   The result 'record' element.
   *
   * @throws \UnexpectedValueException
   * @throws \Drupal\Core\Entity\Exception\UndefinedLinkTemplateException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \InvalidArgumentException
   */
  private function createEntityRecord(\DOMDocument $xml, EntityInterface $entity, $action = self::FEED_ACTION_ADD): \DOMElement {
    if ($entity->getType() === 'news_link') {
      $entitySeoUrl = $entity->get('field_url_link')->getValue()[0]['uri'];

      // If the target URL is external, return that.
      // Otherwise we need to generate the absolute internal URL.
      $urlHelper = Url::fromUri($entitySeoUrl, ['absolute' => TRUE]);
      if (FALSE === $urlHelper->isExternal()) {
        $entitySeoUrl = $urlHelper->toString(TRUE)
          ->getGeneratedUrl();
      }
    }
    else {
      $entitySeoUrl = $entity->toUrl('canonical', ['absolute' => TRUE])
        ->toString(TRUE)
        ->getGeneratedUrl();
    }

    $entityInternalUrl = $this->httpHost . '/' . $entity->toUrl()
      ->getInternalPath();

    $entityUpdateDate = (new DateTime())->setTimestamp($entity->getChangedTime())->format(static::UPDATE_DATETIME_FORMAT);

    $record = $xml->createElement('record');

    $record->setAttribute('url', $entityInternalUrl);
    $record->setAttribute('displayurl', $entitySeoUrl);
    // Attr mimetype: required, but only used for content feeds.
    $record->setAttribute('mimetype', 'text/html');
    // Attr crawl-immediately: for web and metadata-and-url feeds only.
    $record->setAttribute('crawl-immediately', 'true');
    // last-modified  -> RFC822 (Mon, 15 Nov 2004 04:58:08 GMT)
    // Authmethod: none, ntlm, httpbasic, or httpsso.
    $record->setAttribute('authmethod', 'httpsso');
    $record->setAttribute('last-modified', $entityUpdateDate);
    // Add is the default action, no need to add that.
    if ($action === 'delete') {
      $record->setAttribute('action', $action);
    }

    return $record;
  }

}
