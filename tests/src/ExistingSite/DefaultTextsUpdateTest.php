<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_bundle_people_logic\ExistingSite;

use Drupal\texts\TextsInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * The people texts update adds the missing texts (ICMS-862).
 *
 * The bundle imported its texts on install only, so a site that had the
 * bundle before the "Listed only" notice was added lacked it. The first
 * frontend request then created it in the site's default language with the
 * English fallback of the frontend.
 */
#[Group('icms_bundle_people')]
class DefaultTextsUpdateTest extends ExistingSiteBase {

  /**
   * The text the update has to create.
   */
  protected const MISSING = ['key' => 'listed_only_notice', 'context' => 'bundle_people'];

  /**
   * The text an editor changed.
   */
  protected const CHANGED = ['key' => 'appointment_button_label', 'context' => 'bundle_people'];

  /**
   * The missing text's stored translations by langcode, restored after.
   *
   * @var array<string, string>|null
   */
  protected ?array $missingTranslations = NULL;

  /**
   * The changed text's stored value, restored after the test.
   */
  protected ?string $changedValue = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!\Drupal::moduleHandler()->moduleExists('icms_bundle_people_logic')) {
      $this->markTestSkipped('The people bundle is not installed on this site.');
    }
    require_once dirname(__DIR__, 3) . '/icms_bundle_people_logic.install';
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->changedValue !== NULL) {
      $this->loadText(self::CHANGED)?->setTranslationText($this->changedValue)->save();
    }
    if ($this->missingTranslations !== NULL) {
      $this->loadText(self::MISSING)?->delete();
      $text = \Drupal::entityTypeManager()->getStorage('texts')->create([
        'key' => self::MISSING['key'],
        'context' => self::MISSING['context'],
        'langcode' => array_key_first($this->missingTranslations),
        'translation' => reset($this->missingTranslations),
      ]);
      foreach (array_slice($this->missingTranslations, 1, NULL, TRUE) as $langcode => $value) {
        $text->addTranslation($langcode, ['translation' => $value]);
      }
      $text->save();
    }
    parent::tearDown();
  }

  /**
   * The update creates the missing text and keeps the changed one.
   */
  public function testUpdateAddsMissingTexts(): void {
    $changed = $this->loadText(self::CHANGED);
    $this->assertNotNull($changed, 'The appointment_button_label text exists.');
    $this->changedValue = $changed->getTranslationText();
    $changed->setTranslationText('Changed by an editor')->save();

    $missing = $this->loadText(self::MISSING);
    if ($missing) {
      $this->missingTranslations = [];
      foreach ($missing->getTranslationLanguages() as $langcode => $language) {
        $this->missingTranslations[$langcode] = $missing->getTranslation($langcode)->getTranslationText();
      }
      $missing->delete();
    }

    icms_bundle_people_logic_update_11004();

    $this->assertSame('Changed by an editor', $this->loadText(self::CHANGED)?->getTranslationText());

    // The frontend requests the text with its English fallback.
    $default = Yaml::parseFile(dirname(__DIR__, 3) . '/texts/texts.yml')[self::MISSING['key']]['default'];
    $langcodes = array_intersect(array_keys(\Drupal::languageManager()->getLanguages()), array_keys($default));
    $this->assertNotEmpty($langcodes, 'The site has a language the texts file translates.');
    foreach ($langcodes as $langcode) {
      $translation = \Drupal::service('texts.translator')->trans(self::MISSING['key'], [], [
        'context' => self::MISSING['context'],
        'default_translation' => $default['en'],
        'langcode' => $langcode,
      ]);
      $this->assertSame($default[$langcode], $translation, "The listed_only_notice text in $langcode.");
    }
  }

  /**
   * Loads a text in its original language.
   *
   * @param array{key: string, context: string} $text
   *   The text key and context.
   */
  protected function loadText(array $text): ?TextsInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('texts');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('key', $text['key'])
      ->condition('context', $text['context'])
      ->execute();
    return $ids ? $storage->loadUnchanged(reset($ids)) : NULL;
  }

}
