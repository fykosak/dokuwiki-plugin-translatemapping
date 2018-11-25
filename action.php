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
     * @var null|string Language of the DW if it has been changed
     */
    private $locale = null;

    /**
     * @var array List of language names by code
     */
    private $langnames = [
        'cs' => 'česky',
        'en' => 'English',
    ];
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

        // Translate javascript because it contains translations for some plugins
        // It sets GET parameter to js.php link and then reads it to determine which translation should be used.
        // @see https://github.com/splitbrain/dokuwiki-plugin-translation/blob/master/action.php
        if (basename($_SERVER['PHP_SELF']) == 'js.php') {
            $controller->register_hook('INIT_LANG_LOAD', 'BEFORE', $this, 'translation_js');
            $controller->register_hook('JS_CACHE_USE', 'BEFORE', $this, 'translation_jscache');
        } else {
            $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'setJsCacheKey');
        }
    }

    /**
     * Hook Callback. Pass language code to JavaScript dispatcher
     * @see https://github.com/splitbrain/dokuwiki-plugin-translation/blob/master/action.php
     *
     * @param Doku_Event $event
     * @param $args
     * @return bool
     */
    function setJsCacheKey(Doku_Event $event, $args) {
        if(!isset($this->locale)) return false;
        $count = count($event->data['script']);
        for($i = 0; $i < $count; $i++) {
            if(strpos($event->data['script'][$i]['src'], '/lib/exe/js.php') !== false) {
                $event->data['script'][$i]['src'] .= '&lang=' . hsc($this->locale);
            }
        }
        return false;
    }

    /**
     * Hook Callback. Load correct translation when loading JavaScript
     * @see https://github.com/splitbrain/dokuwiki-plugin-translation/blob/master/action.php
     *
     * @param Doku_Event $event
     * @param $args
     */
    function translation_js(Doku_Event $event, $args) {
        global $conf;
        if(!isset($_GET['lang'])) return;
        $lang = $_GET['lang'];
        $event->data = $lang;
        $conf['lang'] = $lang;
    }

    /**
     * Hook Callback. Make sure the JavaScript is translation dependent
     * @see https://github.com/splitbrain/dokuwiki-plugin-translation/blob/master/action.php
     *
     * @param Doku_Event $event
     * @param $args
     */
    function translation_jscache(Doku_Event $event, $args) {
        if(!isset($_GET['lang'])) return;

        $lang = $_GET['lang'];
        // reuse the constructor to reinitialize the cache key
        $event->data->__construct(
            $event->data->key . $lang,
            $event->data->ext
        );
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
        if ($pageLang && $conf['lang'] != $pageLang) {
            $conf['lang'] = $pageLang;
            $this->locale = $pageLang;
        }

        // Remove actual language from list of translations
        unset($langPaths[$pageLang]);
        if (isset($defaults[$pageLang])) {
            unset($defaults[$pageLang]);
        }

        // Redirect if URL is invalid
        $domain = $this->getDomainByLang($conf['lang']);
        if ($domain && substr($_SERVER['HTTP_HOST'], -strlen($domain)) !== $domain) {
            send_redirect($this->getConf('host_prefix') . $domain . wl($ID));
        }

        // Try to find existing pages from translations
        $translations = $this->cropLangPaths($langPaths, $defaults);

        // Set available translations
        foreach ($translations as $lang => $translation) {
            $domain = $this->getDomainByLang($lang);
            $conf['available_lang'][] = [
                'content' => ['text' => sprintf($this->getConf('translation_format'), $this->getLangName($lang), p_get_first_heading($translation), $lang), 'url' => ($domain ? $this->getConf('host_prefix') . $domain : '') . wl($translation), 'class' => '', 'more' => ''],
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
                    $tokens = explode(',', substr($dataLine, $p+2));
                    foreach ($tokens as $token) {
                        list($lang, $translation) = explode(':', $token, 2);
                        $defaults[$lang] = $translation;
                    }
                }

                /**
                 * $level - 1 === $inLevel  Possible match
                 * $level - 1 > $inLevel    Wrong branch, could be skipped
                 * $level - 1 < $inLevel    The branch we were looking for has been explored
                 */

                if ($level - 1 === $inLevel && $level < count($path)) { // Possible match
                    $words = explode(';', substr($dataLine, $p+2));
                    foreach ($words as $word) { // There could be more groups of words (word is "en page cs stranka <language> <translation>")
                        $tokens = explode(',', $word);
                        foreach ($tokens as $token) {
                            list($lang, $translation) = explode(':', $token, 2);

                            if ((!$pageLang || $lang === $pageLang) && preg_match('/^' . str_replace('*', '(.*)', $translation) . '$/', $path[$level], $matches)) { // Match found
                                $pageLang = $lang;
                                $inLevel++;
                                foreach ($tokens as $sameToken) { // Save all language variations
                                    list($lang, $translation) = explode(':', $sameToken, 2);
                                    $langPaths[$lang][$inLevel] = str_replace('*', $matches[1], $translation);
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
                // Try full path
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
            if (isset($defaults[$lang])) {
                $cropped[$lang] = $defaults[$lang];
            }
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
        $tokens = explode(',', $this->getConf('http_hosts_by_lang', ''));
        foreach ($tokens as $token) {
            list ($l, $d) = explode(':', $token, 2);
            if ($l === $lang) {
                return $d;
            }
            $default = $default ?: $d;
        }

        return $default;
    }

    /**
     * @param string $lang
     * @return string|null
     */
    private function getLangName($lang)
    {
        return isset($this->langnames[$lang]) ? $this->langnames[$lang] : null;
    }
}
