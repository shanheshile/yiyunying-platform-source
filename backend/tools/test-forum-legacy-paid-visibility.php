<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'visibility' => $root . '/app/Services/ForumVisibilityService.php',
    'forum' => $root . '/app/Controllers/User/ForumController.php',
    'public' => $root . '/app/Controllers/PublicApi/ForumController.php',
    'favorites' => $root . '/app/Controllers/User/FavoriteController.php',
    'social' => $root . '/app/Controllers/User/SocialController.php',
    'experience' => $root . '/app/Services/ForumExperienceService.php',
];
$contents = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $contents[$name] = (string) file_get_contents($path);
}

$checks = [
    'locked body is replaced by preview' => str_contains($contents['visibility'], "\$item['content'] = \$preview"),
    'locked tags are removed' => str_contains($contents['visibility'], "\$item['tags'] = []"),
    'locked attachments are removed before hydrate' => strpos($contents['visibility'], 'redactLockedPost')
        < strpos($contents['visibility'], 'MessageMediaService::hydrate'),
    'keyword search gates original body by entitlement' => str_contains($contents['visibility'], 'legacyUnlockedClause')
        && str_contains($contents['visibility'], '{$postAlias}.content LIKE ?'),
    'public detail uses visibility policy' => str_contains($contents['public'], 'ForumVisibilityService::hydratePosts'),
    'forum collections use visibility policy' => substr_count($contents['forum'], 'ForumVisibilityService::hydratePosts') >= 5,
    'forum search uses visibility policy' => substr_count($contents['forum'], 'ForumVisibilityService::keywordClause') >= 2,
    'favorite center uses visibility policy' => str_contains($contents['favorites'], 'ForumVisibilityService::hydratePosts'),
    'public user profile uses visibility policy' => str_contains($contents['social'], 'ForumVisibilityService::hydratePosts'),
    'legacy whole-post lock also gates chapter content' => str_contains($contents['experience'], '$legacyPostUnlocked')
        && str_contains($contents['experience'], '$unlocked = $legacyPostUnlocked && $sectionUnlocked')
        && str_contains($contents['experience'], "\$section['blocked_by_post_purchase'] = !\$legacyPostUnlocked"),
];
foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Forum legacy paid visibility contract failed: {$name}\n");
        exit(1);
    }
}
echo "Forum legacy paid visibility contract: passed\n";
