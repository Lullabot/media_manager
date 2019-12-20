INTRODUCTION
------------

This module provides integration with PBS Media Manager API. This module is not
officially affiliated with, or maintained by, PBS. Use of this module requires
an API key, which can be [requested from
PBS](http://digitalsupport.pbs.org/support/tickets/new). Additional information
about the Media Manager API can be found in the [Developer
Documentation](https://docs.pbs.org/display/CDA/Media+Manager+API).

The code in this module can be used as a basis for a custom module. Or, with
very little configured, this module can be used to sync Media Manager Shows,
Seasons, and Episodes with Drupal content. When used in this way, you must
create three content types -- such as Show, Season, and Episode -- and map the
fields that you would like to sync with the API.

Rather than providing custom hooks or event listeners to alter API data, the
suggested use of this module is to import data into the node, hide any fields
that you will not use directly, and then create other fields that can alter
the data either when content is displayed or when the node is saved.


REQUIREMENTS
------------

This module requires the following libraries:

 * The [PHP client for interacting with the PBS Media Manager
   API](https://github.com/OpenPublicMedia/pbs-media-manager-php)
   that is part of [Open Public Media](https://github.com/OpenPublicMedia)


RECOMMENDED MODULES
-------------------

 * Media (https://www.drupal.org/project/views)
 * Social Links??


INSTALLATION
------------

* Install as you would normally install a contributed Drupal module.
* To be able to interact with the PBS Media Manager API, the API key and secret
  must be configured. This can be done Administration » Configuration » PBS
  Media Manager API Settings. However, for security considerations, this should
  probably configured in `settings.php`:

```
  $config['media_manager.settings']['api']['key'] = 'YOUR_PBS_MM_API_KEY';
  $config['media_manager.settings']['api']['secret'] = 'YOUR_PBS_MM_API_SECRET';
```

* If you are using this module to sync Shows, and you would like to populate
  a genre field, you must create a taxonomy vocabulary for genre.
* If you would like to sync image fields, such as "Mezzanine" or "Poster," you
  must enable the Media module and use Media image fields rather than core
  Drupal image fields.


CONFIGURATION
-------------

* Configure this module at Administration » Configuration » PBS Media Manager
  API Settings
* Select each content type you would like to sync and save the form.
* Map each of the fields you would like to sync on your content type.


ROADMAP
-------

* Add more fields to map for Show
* Create drush commands to add or update shows, seasons, and episodes
* Create configuration and import support for Season and Episode
* Determine how long important lots of data will take
* Add a separate section specifically to handle deleting content
* Remove the test controller


MAINTAINERS
-----------

Current maintainers:
* Matthew Tift (mtift) - http://drupal.org/user/751908

This project has been sponsored by:
* Georgia Public Broadcasting
* Lullabot
