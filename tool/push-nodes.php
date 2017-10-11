<?php

/**
 * @file
 * Helper script for pushing every (elevation) node to the GSA device.
 */

use Drupal\gsa_feed\Service\FeedClient;

$whitelist = \Drupal::config(FeedClient::GSA_FEED_CONFIG_KEY)->get('gsa_node_type_whitelist');

$nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
  'type' => $whitelist,
]);

/** @var \Drupal\gsa_feed\Service\FeedClient $service */
$service = \Drupal::service('gsa_feed.feed_client');
$service->setFeedType(FeedClient::FEED_TYPE_FULL);
//echo($service->createFeed($nodes));
$service->pushMultipleEntities($nodes);
