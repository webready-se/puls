<?php

test('detects AI bots', function (string $ua, string $name) {
    $result = detect_bot($ua);
    expect($result)->not->toBeNull()
        ->and($result['name'])->toBe($name)
        ->and($result['category'])->toBe('AI');
})->with([
    ['Mozilla/5.0 (compatible; GPTBot/1.0)', 'GPTBot'],
    ['Mozilla/5.0 (compatible; ClaudeBot/1.0)', 'ClaudeBot'],
    ['Mozilla/5.0 (compatible; PerplexityBot/1.0)', 'PerplexityBot'],
    ['Mozilla/5.0 (compatible; Bytespider)', 'Bytespider'],
    ['Meta-ExternalAgent/1.0', 'Meta AI'],
]);

test('detects search engine bots', function (string $ua, string $name) {
    $result = detect_bot($ua);
    expect($result)->not->toBeNull()
        ->and($result['name'])->toBe($name)
        ->and($result['category'])->toBe('Search engine');
})->with([
    ['Mozilla/5.0 (compatible; Googlebot/2.1)', 'Googlebot'],
    ['Mozilla/5.0 (compatible; bingbot/2.0)', 'Bingbot'],
]);

test('detects social bots', function (string $ua, string $name) {
    $result = detect_bot($ua);
    expect($result)->not->toBeNull()
        ->and($result['name'])->toBe($name)
        ->and($result['category'])->toBe('Social');
})->with([
    ['facebookexternalhit/1.1', 'Facebook'],
    ['Twitterbot/1.0', 'Twitterbot'],
    ['LinkedInBot/1.0', 'LinkedInBot'],
]);

test('detects SEO bots', function () {
    $result = detect_bot('Mozilla/5.0 (compatible; AhrefsBot/7.0)');
    expect($result['name'])->toBe('AhrefsBot')
        ->and($result['category'])->toBe('SEO');
});

test('detects monitor bots', function (string $ua, string $name) {
    $result = detect_bot($ua);
    expect($result)->not->toBeNull()
        ->and($result['name'])->toBe($name)
        ->and($result['category'])->toBe('Monitor');
})->with([
    ['UptimeRobot/2.0', 'UptimeRobot'],
    ['Mozilla/5.0 CensysInspect/1.1', 'CensysInspect'],
    ['spatie/laravel-uptime-monitor uptime checker', 'Uptime Checker'],
]);

test('detects automated clients', function (string $ua, string $name) {
    $result = detect_bot($ua);
    expect($result)->not->toBeNull()
        ->and($result['name'])->toBe($name)
        ->and($result['category'])->toBe('Client');
})->with([
    ['axios/1.8.4', 'Axios'],
    ['python-requests/2.31.0', 'Python Requests'],
    ['Go-http-client/2.0', 'Go HTTP'],
    ['curl/8.4.0', 'curl'],
    ['wget/1.21', 'Wget'],
    ['node-fetch/1.0', 'Node Fetch'],
    ['HeadlessChrome/120.0', 'Headless Chrome'],
    ['Scrapy/2.11', 'Scrapy'],
]);

// Named from a pass over the unknown visits of two installs (user agents as they arrived)
test('detects bots named from the unknown pass', function (string $ua, string $name, string $category) {
    $result = detect_bot($ua);
    expect($result)->not->toBeNull()
        ->and($result['name'])->toBe($name)
        ->and($result['category'])->toBe($category);
})->with([
    ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot) Chrome/119.0.6045.214 Safari/537.36', 'Amazonbot', 'AI'],
    ['Mozilla/5.0 (compatible; ExaSearchBot/1.0; +https://crawler.exa.ai/)', 'Exa', 'AI'],
    ['Mozilla/5.0 (compatible; ElevenlabsCrawler/1.0; +https://elevenlabs.io/docs/bots)', 'ElevenLabs', 'AI'],
    ['Mozilla/5.0 (compatible; Reflectionbot/1.0; +https://reflection.ai/bot)', 'Reflection AI', 'AI'],
    ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; AzureAI-SearchBot/1.0;', 'Azure AI Search', 'AI'],
    ['Mozilla/5.0 (compatible; Google-CloudVertexBot; +https://cloud.google.com/vertex-ai-bot)', 'Google Vertex AI', 'AI'],
    ['DuckAssistBot/1.2; (+http://duckduckgo.com/duckassistbot.html)', 'DuckAssist', 'AI'],
    ['LinkupBot/1.0 (LinkupBot for web indexing; https://linkup.so/bot; bot@linkup.so)', 'Linkup', 'AI'],
    ['ImageBot/1.0 (compatible; research crawler; +https://github.com/rom1504/img2dataset)', 'img2dataset', 'AI'],
    ['AdsBot-Google (+http://www.google.com/adsbot.html)', 'AdsBot-Google', 'Search engine'],
    ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.8010.52 Mobile Safari/537.36 (compatible; AdsBot-Google-Mobile; +http://www.google.com/mobile/adsbot.html)', 'AdsBot-Google', 'Search engine'],
    ['Mozilla/5.0 (Linux; Android 7.0;) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; PetalBot;+https://webmaster.petalsearch.com/site/petalbot)', 'PetalBot', 'Search engine'],
    ['Sogou web spider/4.0(+http://www.sogou.com/docs/help/webmasters.htm#07)', 'Sogou', 'Search engine'],
    ['Mozilla/5.0 (compatible; SeznamBot/4.0; +https://o-seznam.cz/napoveda/vyhledavani/en/seznambot-crawler/)', 'SeznamBot', 'Search engine'],
    ['YisouSpider', 'Yisou', 'Search engine'],
    ['Mozilla/5.0 (compatible; SeekportBot; +https://bot.seekport.com)', 'Seekport', 'Search engine'],
    ['Mozilla/5.0 (compatible; KakaoBot/1.0; +https://cs.kakao.com/helps_html/1073213012)', 'KakaoTalk', 'Social'],
    ['Snap URL Preview Service; bot; snapchat; https://developers.snap.com/robots', 'Snapchat', 'Social'],
    ['Mozilla/5.0 (compatible; SiteAuditBot/0.97; +http://www.semrush.com/bot.html)', 'Semrush Site Audit', 'SEO'],
    ['Mozilla/5.0 (compatible; SERankingBacklinksBot/1.0; +https://seranking.com/backlinks-crawler)', 'SE Ranking', 'SEO'],
    ['Mozilla/5.0 (compatible; DataForSeoBot/1.0; +https://dataforseo.com/dataforseo-bot)', 'DataForSEO', 'SEO'],
    ['Mozilla/5.0 (compatible; Barkrowler/0.9; +https://babbar.tech/crawler)', 'Babbar', 'SEO'],
    ['serpstatbot/2.1 (advanced backlink tracking bot; https://serpstatbot.com/; abuse@serpstatbot.com)', 'Serpstat', 'SEO'],
    ['Mozilla/5.0 (compatible; AwarioBot/1.0; +https://awario.com/bots.html)', 'Awario', 'SEO'],
    ['DomainStatsBot/1.0 (https://domainstats.com/pages/our-bot)', 'DomainStats', 'SEO'],
    ['SnokaBot/0.1.0 (+https://github.com/webready-se/snoka)', 'Snoka', 'SEO'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko; compatible; Cookiebot/1.0; +http://cookiebot.com/) Chrome/129.0.6668.58 Safari/537.36', 'Cookiebot', 'Monitor'],
    ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 (Stripebot/1.0; +https://docs.stripe.com/stripebot)', 'Stripe', 'Monitor'],
    ['Vaka/0.2.0 (+https://vaka.example.com)', 'Vaka', 'Monitor'],
    ['VakaBot/0.3.0 (+https://vaka.example.com)', 'Vaka', 'Monitor'],
    ['AttractCarsBot/1.0 (+https://www.attractor.fi/crawler)', 'AttractCarsBot', 'Other'],
    ['Taloustutka Web Crawler (For crawling business contacts, news and mentions of other companies.). Runs roughly once per week', 'Taloustutka', 'Other'],
    ['FirmCredBot/1.0 (+https://www.firmcred.com/bot; directory listing compiler; contact: bot@firmcred.com)', 'FirmCred', 'Other'],
]);

test('a bot with no name of its own still falls through to the generic names', function () {
    expect(detect_bot('Mozilla/5.0 (compatible; WebSearchCrawler/0.1; bot-name-TBD)')['name'])->toBe('Unknown bot')
        ->and(detect_bot('br-crawler/0.5')['name'])->toBe('Unknown crawler');
});

test('returns null for regular browsers', function () {
    expect(detect_bot('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0'))
        ->toBeNull();
});
