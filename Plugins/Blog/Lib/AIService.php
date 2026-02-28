<?php
namespace FacturaScripts\Plugins\Blog\Lib;

use FacturaScripts\Core\Tools;

/**
 * AI Service for generating SEO metadata
 * Supports multiple free AI providers: Groq (14,400/day), Cohere (1000/month)
 *
 * @author Your Name
 */
class AIService
{
    private static $providers = [];

    /**
     * Register available AI providers
     */
    public static function init(): void
    {
        if (!empty(self::$providers)) {
            return;
        }

        // Register free AI providers
        if (file_exists(__DIR__ . '/AIProviders/GroqProvider.php')) {
            require_once __DIR__ . '/AIProviders/GroqProvider.php';
            self::$providers['groq'] = new AIProviders\GroqProvider();
        }

        if (file_exists(__DIR__ . '/AIProviders/CohereProvider.php')) {
            require_once __DIR__ . '/AIProviders/CohereProvider.php';
            self::$providers['cohere'] = new AIProviders\CohereProvider();
        }
    }

    /**
     * Generate SEO metadata using AI
     *
     * @param string $title Post title
     * @param string $content Post content
     * @param string $provider AI provider to use (groq, cohere)
     * @return array ['meta_title' => string, 'meta_description' => string, 'meta_keywords' => string]
     */
    public static function generateSEO(string $title, string $content, string $provider = 'groq'): array
    {
        self::init();

        // Get API key from config.php constant or settings
        $apiKey = '';

        // Try to get from config.php constant first
        if ($provider === 'groq' && defined('GROQ_API_KEY')) {
            $apiKey = GROQ_API_KEY;
        } elseif ($provider === 'cohere' && defined('COHERE_API_KEY')) {
            $apiKey = COHERE_API_KEY;
        }

        // Fallback to settings if not in config
        if (empty($apiKey)) {
            $apiKey = Tools::settings('Blog', 'ai_api_key_' . $provider, '');
        }

        if (empty($apiKey)) {
            return [
                'error' => 'No API key configured for ' . $provider,
                'meta_title' => $title,
                'meta_description' => self::generateFallbackDescription($content),
                'meta_keywords' => self::generateFallbackKeywords($title)
            ];
        }

        // Get provider instance
        if (!isset(self::$providers[$provider])) {
            return [
                'error' => 'Provider not available: ' . $provider,
                'meta_title' => $title,
                'meta_description' => self::generateFallbackDescription($content),
                'meta_keywords' => self::generateFallbackKeywords($title)
            ];
        }

        try {
            return self::$providers[$provider]->generateSEO($title, $content, $apiKey);
        } catch (\Exception $e) {
            Tools::log()->error('AI SEO Generation error: ' . $e->getMessage());
            return [
                'error' => $e->getMessage(),
                'meta_title' => $title,
                'meta_description' => self::generateFallbackDescription($content),
                'meta_keywords' => self::generateFallbackKeywords($title)
            ];
        }
    }

    /**
     * Fallback: Generate description from content (no AI)
     */
    private static function generateFallbackDescription(string $content): string
    {
        $text = strip_tags($content);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (strlen($text) <= 160) {
            return $text;
        }

        return substr($text, 0, 157) . '...';
    }

    /**
     * Fallback: Generate keywords from title (no AI)
     */
    private static function generateFallbackKeywords(string $title): string
    {
        $words = explode(' ', $title);
        $keywords = array_filter($words, function($word) {
            return strlen($word) > 4; // Only words with 5+ characters
        });

        return implode(', ', array_slice($keywords, 0, 5));
    }

    /**
     * Get list of available providers
     */
    public static function getAvailableProviders(): array
    {
        self::init();
        return array_keys(self::$providers);
    }
}
