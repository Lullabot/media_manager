<?php

namespace Drupal\media_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\media_manager\ApiClient;
use OpenPublicMedia\PbsMediaManager\Client;

class MediaManagerController extends ControllerBase {

  // This whole controller is just for testing purposes.

  public function content() {

    #############################
    # TEST SHOW MANAGER SERVICE #
    #############################

    $config = \Drupal::config('media_manager.settings');
    $showManager = \Drupal::service('media_manager.show_manager');
    $show_ids = explode("\r\n", $config->get('shows.show_ids'));
    foreach($show_ids as $show_id) {
      $show = $showManager->getShow($show_id);
      $showManager->addOrUpdateShow($show);
    }

    ###############
    # TEST CLIENT #
    ###############

    $api_key = $config->get('api.key');
    $api_secret = $config->get('api.secret');
    $client = new Client($api_key, $api_secret);

    #########
    # SHOWS #
    #########

    $show_mm_id = '2e5c2027-ec2e-4214-baa3-6ff6af56c8c3';
    $show = $client->getShow($show_mm_id);

    if (!empty($show)) {

      // $episode = $client->getEpisode('08e7ee9c-800a-406f-86f0-bf0bb77fe42b');
      $attributes = $show->attributes;

      // Get image data.
      $images = $this->parseImages($attributes->images);
      if (isset($images['show-mezzanine16x9'])) {
        $mezzanine_image = $images['show-mezzanine16x9'];
      }
      if (isset($images['show-poster2x3'])) {
        $show_poster = $images['show-poster2x3'];
      }

      // A GPB Original is a show with the value of "WGTV" in the
      // `attributes->audience->attributes->call_sign` field. See, for example:
      // https://media.services.pbs.org/api/v1/shows/9763eb84-f67d-4cff-9e59-f8360a83aed8/
      //
      // This is a show that is NOT a GPB original, where station is NULL:
      // https://media.services.pbs.org/api/v1/shows/6d221f14-f71e-4888-a0a7-d0129e9fc5c5/
      $stations = array_column($attributes->audience, 'station');
      $call_sign = '';
      foreach ($stations as $station) {
        if (isset($station->type) && $station->type == 'station') {
          $call_sign = $station->attributes->call_sign;
        }
      }
      $call_sign == "WGTV" ? $gpb_original = "TRUE ($call_sign)" : $gpb_original = "FALSE";

      if (isset($attributes->audience->attributes->call_sign)) {
        $call_sign = $attributes->audience->attributes->call_sign;
      }

      $table = [
        '#theme' => 'table',
        '#header' => [t('Field'), t('Data')],
        '#rows' => [
          ['Title', $attributes->title],
          ['Media Manager ID', $show_mm_id],
          ['TMS ID', $attributes->tms_id],
          ['Deck', $attributes->description_short],
          ['Long Description', $attributes->description_long],
          ['About', 'NOT FROM API'],
          ['Mezzanine Image', $mezzanine_image],
          ['Show Poster', $show_poster],
          ['GPB Original', $gpb_original],
          ['Available In Passport', 'NOT FROM API'],
          ['Hosts', 'NOT FROM API'],
        ],
      ];

      $links = array_column($attributes->links, 'value', 'profile');
      foreach ($links as $value => $profile) {
        $table['#rows'][] = ['Social: ' . $value, $profile];
      }

      $table['#rows'][] = ['Related Shows', 'NOT FROM API'];

      // Determine genre.
      if (isset($attributes->genre->title)) {
        $genre = $attributes->genre->title;
      }
      else {
        $genre = 'No genre returned';
      }
      $table['#rows'][] = ['Genre', $genre];

      // Determine NOLA.
      if (isset($attributes->nola) && !empty($attributes->nola)) {
        $nola = $attributes->nola;
      }
      else {
        $nola = 'No nola returned';
      }
      $table['#rows'][] = ['NOLA', $nola];


      ###########
      # SEASONS #
      ###########

      $table['#rows'][] = ['---SEASON---', '---DATA---'];
      $seasons = $client->getSeasons($show_mm_id);
      $season_count = $seasons->count();
      $table['#rows'][] = ['Season Count', $season_count];
      foreach ($seasons as $season) {

        $attributes = $season->attributes;
        $table['#rows'][] = ['Season Media Manager ID', $season->id];
        $table['#rows'][] = ['Season Number', $attributes->ordinal];
        $table['#rows'][] = ['Season Title', $attributes->title];
        $table['#rows'][] = ['Short Description', $attributes->description_short];
        $table['#rows'][] = ['Long Description', $attributes->description_long];

        // For now we will only pull one season.
        break;
      }

      ############
      # EPISODES #
      ############

      // NOTE: Using the $attributes->episodes from the season above only
      // returns partial data, and does not include fields such as tms_id,
      // description_long, and description_short.

      // This $season->id will get the last/only season from above.
      $season_id = $season->id;
      $episodes = $client->getEpisodes($season_id);

        foreach ($episodes as $episode) {
          // dpm($episode);
          $attributes = $episode->attributes;
          $table['#rows'][] = ['---EPISODE---', '---DATA---'];
          $table['#rows'][] = ['Episode Title', $attributes->title];
          $table['#rows'][] = ['Ordinal', $attributes->ordinal];
          $table['#rows'][] = ['Episode Media Manager ID', $episode->id];
          $table['#rows'][] = ['TMS ID', $attributes->tms_id];
          $table['#rows'][] = ['Long Description', $attributes->description_long];
          $table['#rows'][] = ['Short Description', $attributes->description_short];
          $table['#rows'][] = ['Episode Type', $episode->type];
          $table['#rows'][] = ['Mezzanine Image', 'CREATE LOGIC'];
          $table['#rows'][] = ['Airdate', $attributes->premiered_on];
          $table['#rows'][] = ['Show ID', $season_id];
          $table['#rows'][] = ['Partner Player', 'WHAT IS THIS?'];
          $table['#rows'][] = ['Show', 'SHOULD BE DOABLE, BUT REQUIRES LOGIC'];
          $table['#rows'][] = ['Season', 'SHOULD BE DOABLE, BUT REQUIRES LOGIC'];

          // For now we will only pull one episode.
          break;
        }


      return $table;
    }

    return [
      '#type' => 'markup',
      '#markup' => 'Failed to get show',
    ];
  }

  /**
   * Convert images array to key by profile and enforce site scheme.
   *
   * This is necessary because some images provided by Media Manager use an
   * "http" scheme. This will cause mixed media errors and prevent images from
   * loading because the website uses an "https" scheme.
   *
   * @param array $images
   *   Images from a Media Manager query.
   * @param string $image_key
   *   Images array key containing the image URL.
   * @param string $profile_key
   *   Images array key containing the image profile string.
   *
   * @return array
   *   All valid images keyed by profile string using a "\\" scheme to match the
   *   site scheme.
   */
  public function parseImages(
    array $images,
    string $image_key = 'image',
    string $profile_key = 'profile'
  ): array {
    $images = array_column($images, $image_key, $profile_key);
    foreach ($images as $key => $image) {
      $parts = parse_url($image);
      if ($parts === FALSE || !isset($parts['host']) || !isset($parts['path'])) {
        unset($images[$key]);
      }
      else {
        $images[$key] = sprintf('https://%s%s', $parts['host'], $parts['path']);
      }
    }
    return $images;
  }
}
