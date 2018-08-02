<?php
/**
 * DokuWiki Plugin translatemapping (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Štěpán Stenchlák <s.stenchlak@gmail.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

class action_plugin_translatemapping extends DokuWiki_Action_Plugin
{

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     *
     * @return void
     */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_action_act_preprocess');
    }

    /**
     * [Custom event handler which performs action]
     *
     * Called for event:
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     *
     * @return void
     */
    public function handle_action_act_preprocess(Doku_Event $event, $param)
    {
        global $ID, $conf;

        // Search language file for information about $ID
        list($pageLang, $langPaths, $defaults) = $this->findLanguageTree($ID);

        // Set page lang
        if ($pageLang) $conf['lang'] = $pageLang;

        // Remove actual language from list of translations
        unset($langPaths[$pageLang]);
        if (isset($defaults[$pageLang])) unset($defaults[$pageLang]);

        // Redirect if URL is invalid
        $domain = $this->getDomainByLang($conf['lang']);
        if ($domain && substr($_SERVER['HTTP_HOST'], -strlen($domain)) !== $domain) {
            send_redirect($this->getConf('host_prefix') . $domain . wl($ID));
        }

        // Try to find existing pages from translations
        $translations = $this->cropLangPaths($langPaths, $defaults);

        // Set available translations
        foreach ($translations as $lang => $translation) {
            $conf['available_lang'][] = [
                'content' => ['text' => str_replace(':', ' ', $translation), 'url' => $this->getConf('host_prefix') . $this->getDomainByLang($lang) . wl($translation), 'class' => '', 'more' => ''],
                'code' => $lang
            ];
        }
    }

    /**
     * Tries to find the language of the $id and translate the path to different languages.
     * @param string $id
     * @return mixed
     */
    private function findLanguageTree($id)
    {
        $path = explode(':', $id);
        $inLevel = -1;
        $pageLang = null;
        $langPaths = [];
        $defaults = [];

        if ($lid = $this->getConf('language_data_page', false)) {
            $dataLines = explode("\n", rawWiki($lid));
            foreach ($dataLines as $lineNumber => $dataLine) {
                $p = strpos($dataLine, '*');
                $level = $p/2 - 1; // Levels are counted from 0

                /**
                 * Special case: first line (mail pages)
                 */
                if ($lineNumber === 0) {
                    $tokens = explode(' ', substr($dataLine, $p+2));
                    for ($i = 0; $i < count($tokens); $i+=2) {
                        $defaults[$tokens[$i]] = $tokens[$i + 1];
                    }
                }

                /**
                 * $level - 1 === $inLevel  Possible match
                 * $level - 1 > $inLevel    Wrong branch, could be skipped
                 * $level - 1 < $inLevel    The branch we were looking for has been explored
                 */

                if ($level - 1 === $inLevel && $level < count($path)) { // Possible match
                    $words = explode(', ', substr($dataLine, $p+2));
                    foreach ($words as $word) { // There could be more groups of words (word is "en page cs stranka <language> <translation>")
                        $tokens = explode(' ', $word);
                        for ($i = 0; $i+1 < count($tokens); $i+=2) {
                            $lang = $tokens[$i];
                            $translation = $tokens[$i+1];

                            if ((!$pageLang || $lang === $pageLang) && preg_match('/^' . str_replace('*', '(.*)', $translation) . '$/', $path[$level], $mathes)) { // Match found
                                $pageLang = $lang;
                                $inLevel++;
                                for ($i = 0; $i < count($tokens); $i+=2) { // Save all language variations
                                    $langPaths[$tokens[$i]][$inLevel] = str_replace('*', $mathes[1], $tokens[$i+1]);
                                }

                                break 2;
                            }
                        }
                    }

                } elseif ($level - 1 < $inLevel) { // The branch we were looking for has been explored
                    break;
                }
            }
        }
        
        return [$pageLang, $langPaths, $defaults];
    }

    /**
     * Tries to find the nearest existing page
     * @param string[][] $langPaths Path to hypothetical page by language
     * @param string[] $defaults    List of main pages by language
     * @return string[]
     */
    private function cropLangPaths(array $langPaths, array $defaults)
    {
        $cropped = [];
        $langs = array_keys(array_merge($langPaths, $defaults));
        foreach ($langs as $lang){
            if (isset($langPaths[$lang])) {
                // Try full paht
                if (page_exists($p = implode(':', $langPaths[$lang]))) {
                    $cropped[$lang] = $p;
                    break;
                }

                // Try crop it and add :start
                for ($i = count($langPaths[$lang]); $i > 0; $i--) {
                    if (page_exists($p = implode(':', array_slice($langPaths[$lang], 0, $i)) . ':start')) {
                        $cropped[$lang] = $p;
                        break 2;
                    }
                }
            }

            // Use default
            if (isset($defaults[$lang]))
                $cropped[$lang] = $defaults[$lang];
        }

        return $cropped;
    }

    /**
     * @param $lang
     * @return null|string
     */
    private function getDomainByLang($lang)
    {
        $default = null;
        $tokens = explode(' ', $this->getConf('http_hosts_by_lang', ''));
        for ($i = 0; $i+1 < count($tokens); $i+=2) {
            if ($tokens[$i] === $lang)
                return $tokens[$i+1];
            $default = $default ?: $tokens[$i+1];
        }

        return $default;
    }

}
