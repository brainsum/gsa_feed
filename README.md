# GSA Feed
The purpose of the module is to provide an API that can push content to the GSA device.

# Usage
## Manual (e.g for testing)
* Set up the GSA device to accept the feed data. See:
https://www.google.com/support/enterprise/static/gsa/docs/admin/74/gsa_doc_set/quick_start/quick_start_crawl.html#1085511
* Add these to your settings.php and change them according to your setup.
```
$config['gsa_feed.settings'] = [
  // Optional.
  'gsa_admin_user' => 'exampleuser',
  // Optional.
  'gsa_admin_password' => 'examplepass',
  // Required.
  'gsa_host' => 'example.com',
  // Required.
  'gsa_node_type_whitelist' => [
    'basic',
    'article',
  ],
];
```
* Enable the module
* Optional: Add the GSA host to the CORS settings.
* Use tool/push-nodes.php to push every node to the GSA device (for testing purposes)
```PHP
cd web
drush php-script {path}/gsa_feed/tool/push-nodes.php
```

## Automatic
The module implements hooks to call the API on entity create, update and delete.

# Miscellaneous
## Validations (for testing and debugging purposes only)
If you want to validate the XML (in case of errors) follow the instructions in the tool/validate.php @file comment.

## Plugins
The feeds plugins are deprecated, use the FeedClient API.

## Supported entities
Currently the module only supports nodes.
