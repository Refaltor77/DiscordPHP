<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP project.
 *
 * Copyright (c) 2015-2022 David Cole <david.cole1340@gmail.com>
 * Copyright (c) 2020-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

use Discord\Discord;
use Discord\Helpers\ValidatesDiscordLimits;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Embed\Field;
use Discord\Parts\Part;
use PHPUnit\Framework\TestCase;

final class EmbedUnitTest extends TestCase
{
    /**
     * Regression test for Bug CRITIQUE #3 — `Embed::addField()` permit 26
     * fields instead of 25.
     *
     * The previous check `count($this->fields) > 25` only triggered AFTER a
     * 26th field had been accepted. Adding a 26th field made the embed
     * exceed the documented limit (Discord returns HTTP 400). The fix uses
     * `>= EMBED_FIELDS_MAX` so the 26th field is rejected up front.
     */
    public function testAddFieldRejects26thField(): void
    {
        $embed = $this->makeEmbed();

        for ($i = 0; $i < ValidatesDiscordLimits::EMBED_FIELDS_MAX; $i++) {
            $embed->addField(['name' => "n$i", 'value' => "v$i"]);
        }

        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessageMatches(
            '/^Embeds can not have more than '.ValidatesDiscordLimits::EMBED_FIELDS_MAX.' fields\.$/'
        );

        $embed->addField(['name' => 'n25', 'value' => 'v25']);
    }

    public function testAddFieldAccepts25thFieldExactly(): void
    {
        $embed = $this->makeEmbed();

        for ($i = 0; $i < ValidatesDiscordLimits::EMBED_FIELDS_MAX; $i++) {
            $embed->addField(['name' => "n$i", 'value' => "v$i"]);
        }

        $this->assertCount(ValidatesDiscordLimits::EMBED_FIELDS_MAX, $embed->fields);
    }

    public function testAddFieldValuesRejectsFieldNameOver256Characters(): void
    {
        $embed = $this->makeEmbed();
        $longName = str_repeat('a', ValidatesDiscordLimits::EMBED_FIELD_NAME_MAX + 1);

        $this->expectException(\LengthException::class);
        $this->expectExceptionMessageMatches(
            '/^Embed field name can not be longer than '.ValidatesDiscordLimits::EMBED_FIELD_NAME_MAX.' characters$/'
        );

        $embed->addFieldValues($longName, 'value');
    }

    public function testAddFieldValuesAccepts256CharacterName(): void
    {
        $embed = $this->makeEmbed();
        $exactName = str_repeat('a', ValidatesDiscordLimits::EMBED_FIELD_NAME_MAX);

        $embed->addFieldValues($exactName, 'value');

        $this->assertCount(1, $embed->fields);
    }

    public function testAddFieldValuesRejectsFieldValueOver1024Characters(): void
    {
        $embed = $this->makeEmbed();
        $longValue = str_repeat('b', ValidatesDiscordLimits::EMBED_FIELD_VALUE_MAX + 1);

        $this->expectException(\LengthException::class);
        $this->expectExceptionMessageMatches(
            '/^Embed field value can not be longer than '.ValidatesDiscordLimits::EMBED_FIELD_VALUE_MAX.' characters$/'
        );

        $embed->addFieldValues('name', $longValue);
    }

    public function testAddFieldValuesAccepts1024CharacterValue(): void
    {
        $embed = $this->makeEmbed();
        $exactValue = str_repeat('b', ValidatesDiscordLimits::EMBED_FIELD_VALUE_MAX);

        $embed->addFieldValues('name', $exactValue);

        $this->assertCount(1, $embed->fields);
    }

    public function testAddFieldValuesDefaultsInlineToFalse(): void
    {
        $embed = $this->makeEmbed();

        $embed->addFieldValues('n', 'v');

        $raw = $embed->getRawAttributes();
        $this->assertSame(false, $raw['fields'][0]['inline']);
    }

    public function testAddFieldValuesHonoursExplicitInlineTrue(): void
    {
        $embed = $this->makeEmbed();

        $embed->addFieldValues('n', 'v', true);

        $raw = $embed->getRawAttributes();
        $this->assertSame(true, $raw['fields'][0]['inline']);
    }

    public function testAddFieldRejectsWhenPushesTotalBeyond6000Chars(): void
    {
        $embed = $this->makeEmbed();

        // Fill title to EMBED_TITLE_MAX (256), description to EMBED_DESCRIPTION_MAX (4096).
        $embed->setTitle(str_repeat('t', ValidatesDiscordLimits::EMBED_TITLE_MAX));
        $embed->setDescription(str_repeat('d', ValidatesDiscordLimits::EMBED_DESCRIPTION_MAX));

        // 256 + 4096 = 4352 so far. 6000 - 4352 = 1648 chars left before overflow.
        // Add a field that would push us over.
        $this->expectException(\LengthException::class);
        $this->expectExceptionMessageMatches(
            '/^Embed text values collectively can not exceed '.ValidatesDiscordLimits::EMBED_TOTAL_CHARS_MAX.' characters$/'
        );

        $embed->addFieldValues(
            str_repeat('n', ValidatesDiscordLimits::EMBED_FIELD_NAME_MAX),     // 256
            str_repeat('v', ValidatesDiscordLimits::EMBED_FIELD_VALUE_MAX),    // 1024
        );
        // total would be 4352 + 256 + 1024 = 5632 — still under; push more:
        $embed->addFieldValues(
            str_repeat('n', ValidatesDiscordLimits::EMBED_FIELD_NAME_MAX),     // +256 = 5888
            str_repeat('v', ValidatesDiscordLimits::EMBED_FIELD_VALUE_MAX),    // +1024 = 6912 → overflow
        );
    }

    public function testAddFieldPassingFieldInstanceValidatesLengths(): void
    {
        $embed = $this->makeEmbed();

        $longNameField = $this->instantiateFieldWithoutConstructor([
            'name' => str_repeat('n', ValidatesDiscordLimits::EMBED_FIELD_NAME_MAX + 1),
            'value' => 'v',
        ]);

        $this->expectException(\LengthException::class);
        $this->expectExceptionMessageMatches(
            '/^Embed field name can not be longer than '.ValidatesDiscordLimits::EMBED_FIELD_NAME_MAX.' characters$/'
        );

        $embed->addField($longNameField);
    }

    // ============== ADVERSARIAL TESTS ==============

    /**
     * Adversarial: multi-byte UTF-8 characters (emoji) must be counted as
     * grapheme-equivalents via poly_strlen/mb_strlen, not raw byte length.
     * An emoji is typically 1 mb_strlen char but 4 bytes.
     */
    public function testAdversarialUnicodeEmojiCountedByMbStrlenNotBytes(): void
    {
        $embed = $this->makeEmbed();

        // 256 emoji → mb_strlen = 256, strlen = 1024 (each 💥 is 4 bytes).
        // Should be accepted under EMBED_FIELD_NAME_MAX (256).
        $name = str_repeat('💥', ValidatesDiscordLimits::EMBED_FIELD_NAME_MAX);

        $embed->addFieldValues($name, 'v');

        $this->assertCount(1, $embed->fields);
    }

    public function testAdversarialUnicodeEmojiRejectsAt257Characters(): void
    {
        $embed = $this->makeEmbed();

        $name = str_repeat('💥', ValidatesDiscordLimits::EMBED_FIELD_NAME_MAX + 1);

        $this->expectException(\LengthException::class);

        $embed->addFieldValues($name, 'v');
    }

    public function testAdversarialEmptyStringFieldIsAccepted(): void
    {
        $embed = $this->makeEmbed();

        $embed->addFieldValues('', '');

        $this->assertCount(1, $embed->fields);
    }

    public function testAdversarialWhitespaceOnlyFieldIsAccepted(): void
    {
        $embed = $this->makeEmbed();

        $embed->addFieldValues(str_repeat(' ', 200), str_repeat("\t", 200));

        $this->assertCount(1, $embed->fields);
    }

    /**
     * Adversarial: interleaving setTitle() and setDescription() past the
     * 6000 overall limit throws once the threshold is crossed.
     */
    public function testAdversarialOverallLimitEnforcedAcrossMutations(): void
    {
        $embed = $this->makeEmbed();

        $embed->setTitle(str_repeat('t', ValidatesDiscordLimits::EMBED_TITLE_MAX));
        $embed->setDescription(str_repeat('d', ValidatesDiscordLimits::EMBED_DESCRIPTION_MAX));

        $this->expectException(\LengthException::class);
        $this->expectExceptionMessageMatches(
            '/^Embed text values collectively can not exceed '.ValidatesDiscordLimits::EMBED_TOTAL_CHARS_MAX.' characters$/'
        );

        // 256 (title) + 4096 (desc) + 2048 footer = 6400 > 6000.
        $embed->setFooter(str_repeat('f', ValidatesDiscordLimits::EMBED_FOOTER_TEXT_MAX));
    }

    /**
     * Adversarial: 100 sequential addField() calls must each preserve the
     * 25-field invariant.
     */
    public function testAdversarialMassAddFieldPreservesInvariant(): void
    {
        $threwAt = null;

        for ($i = 0; $i < 100; $i++) {
            $embed = $this->makeEmbed();
            try {
                for ($j = 0; $j < 30; $j++) {
                    $embed->addField(['name' => "n$j", 'value' => "v$j"]);
                }
            } catch (\OverflowException) {
                $threwAt = count($embed->fields);
                continue;
            }
        }

        $this->assertSame(ValidatesDiscordLimits::EMBED_FIELDS_MAX, $threwAt);
    }

    // ============== HELPERS ==============

    private function makeEmbed(): Embed
    {
        $http = $this->getMockBuilder(\Discord\Http\Http::class)
            ->disableOriginalConstructor()
            ->getMock();

        $factory = $this->getMockBuilder(\Discord\Factory\Factory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $discord = $this->getMockBuilder(Discord::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCollectionClass', 'getHttpClient', 'getFactory'])
            ->getMock();
        $discord->method('getCollectionClass')->willReturn(\Discord\Helpers\Collection::class);
        $discord->method('getHttpClient')->willReturn($http);
        $discord->method('getFactory')->willReturn($factory);

        $factory->method('part')->willReturnCallback(
            fn (string $class, array $data): Part => new $class($discord, $data, true)
        );

        return new Embed($discord, [], true);
    }

    private function instantiateFieldWithoutConstructor(array $attrs): Field
    {
        $reflection = new \ReflectionClass(Field::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $attrProp = $reflection->getProperty('attributes');
        $attrProp->setValue($instance, $attrs);

        return $instance;
    }
}
