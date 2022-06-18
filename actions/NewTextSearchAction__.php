<?php

/*
 * This file is part of the YesWiki Extension bellerecherche.
 *
 * Authors : see README.md file that was distributed with this source code.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace YesWiki\Bellerecherche;

use YesWiki\Bazar\Controller\EntryController;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Bazar\Service\SearchManager;
use YesWiki\Core\Service\AclService;
use YesWiki\Core\Service\DbService;
use YesWiki\Core\Service\TemplateEngine;
use YesWiki\Core\YesWikiAction;

class NewTextSearchAction__ extends YesWikiAction
{
    public const DEFAULT_TEMPLATE = "newtextsearch.twig";
    public const BY_FORM_TEMPLATE = "newtextsearch-by-form.twig";
    public const MAX_DISPLAY_PAGES = 25;

    protected $aclService;
    protected $dbService;
    protected $entryController;
    protected $entryManager;
    protected $formManager;
    protected $searchManager;
    protected $templateEngine;

    public function formatArguments($arg)
    {
        $this->templateEngine = $this->getservice(TemplateEngine::class);
        $template = (!empty($arg['template']) &&
            !empty(basename($arg['template'])) &&
            $this->templateEngine->hasTemplate("@core/".basename($arg['template'])))
            ? basename($arg['template'])
            : self::DEFAULT_TEMPLATE;
        return [
            // label à afficher devant la zone de saisie
            'label' => isset($arg['label']) && is_string($arg['label']) ? $arg['label'] : _t('WHAT_YOU_SEARCH')." : ",
            // largeur de la zone de saisie
            'size' => isset($arg['size']) && is_scalar($arg['size']) ? intval($arg['size']) : 40,
            // texte du bouton
            'button' => !empty($arg['button']) && is_string($arg['button']) ? $arg['button'] : _t('SEARCH'),
            // texte à chercher
            'phrase' => isset($arg['phrase']) && is_string($arg['phrase']) ? $arg['phrase']: '',
            // séparateur entre les éléments trouvés
            'separator' => isset($arg['separator']) && is_string($arg['separator']) ? htmlspecialchars($arg['separator'], ENT_COMPAT, YW_CHARSET): '',
            'template' =>$template,
            'displaytext' => $this->formatBoolean($arg, $template == self::DEFAULT_TEMPLATE, 'displaytext'),
        ];
    }

    public function run()
    {
        // get services
        $this->aclService = $this->getservice(AclService::class);
        $this->dbService = $this->getservice(DbService::class);
        $this->entryController = $this->getservice(EntryController::class);
        $this->entryManager = $this->getservice(EntryManager::class);
        $this->formManager = $this->getservice(FormManager::class);
        $this->searchManager = $this->getservice(SearchManager::class);

        // récupération de la recherche à partir du paramètre 'phrase'
        $searchText = !empty($this->arguments['phrase']) ? htmlspecialchars($this->arguments['phrase'], ENT_COMPAT, YW_CHARSET) : '';
        
        // affichage du formulaire si $this->arguments['phrase'] est vide
        $displayForm = empty($searchText);

        if (empty($searchText) && !empty($_GET['phrase'])) {
            $searchText = htmlspecialchars($_GET['phrase'], ENT_COMPAT, YW_CHARSET);
        }

        $formsTitles = [];
        if (!empty($searchText)) {
            list('requestfull' => $sqlRequest, 'needles' => $needles) = $this->getSqlRequest($searchText);
            $results = $this->dbService->loadAll($sqlRequest);
            if (empty($results)) {
                $results = [];
            } else {
                $counter = 0;
                foreach ($results as $key => $page) {
                    $results[$key]['hasAccess'] = $this->aclService->hasAccess("read", $page["tag"]);
                    if ($this->arguments['displaytext'] &&
                        empty($this->arguments['separator']) &&
                        $results[$key]['hasAccess'] &&
                        $counter < self::MAX_DISPLAY_PAGES &&
                        $page["tag"] != $this->wiki->tag &&
                        !$this->wiki->IsIncludedBy($page["tag"])) {
                        if ($this->entryManager->isEntry($page["tag"])) {
                            $renderedEntry = $this->entryController->view($page["tag"], '', false); // without footer
                            $results[$key]['preRendered'] = $this->displayNewSearchResult(
                                $renderedEntry,
                                $searchText,
                                $needles
                            );
                        }
                        
                        if (empty($results[$key]['preRendered'])) {
                            $results[$key]['preRendered'] = $this->displayNewSearchResult(
                                $this->wiki->Format($page["body"], 'wakka', $page["tag"]),
                                $searchText,
                                $needles
                            );
                        }
                        $counter += 1;
                    }
                    if ($this->arguments['template'] == self::BY_FORM_TEMPLATE && $results[$key]['hasAccess']) {
                        if ($this->entryManager->isEntry($page["tag"])) {
                            $entry = $this->entryManager->getOne($page["tag"]);
                            if (!empty($entry['id_typeannonce'])) {
                                $results[$key]['form'] =  strval(intval($entry['id_typeannonce']));
                                if (!isset($formsTitles[$results[$key]['form']])) {
                                    $form = $this->formManager->getOne($results[$key]['form']);
                                    $formsTitles[$results[$key]['form']] = $form['bn_label_nature'] ?? $results[$key]['form'];
                                }
                            }
                        } else {
                            $results[$key]['form'] =  'page';
                        }
                    }
                }
            }
        }

        return $this->render("@core/{$this->arguments['template']}", [
            'displayForm' => $displayForm,
            'searchText' => $searchText,
            'args' => $this->arguments,
            'results' => $results ?? [],
            'tag' => $this->params->get('rewrite_mode') ? '' : $this->wiki->tag,
            'formsTitles' => $formsTitles,
        ]);
    }

    private function getSqlRequest(string $searchText): array
    {
        // extract needles with values in list
        // find in values for entries
        $forms = $this->formManager->getAll();
        $needles = $this->searchManager->searchWithLists(str_replace(array('*', '?'), array('', '_'), $searchText), $forms);
        $requeteSQLForList = '';
        if (!empty($needles)) {
            $first = true;
            // generate search
            foreach ($needles as $needle => $results) {
                if (!empty($results)) {
                    if ($first) {
                        $first = false;
                    } else {
                        $requeteSQLForList .= ' AND ';
                    }
                    $requeteSQLForList .= '(';
                    // add regexp standard search
                    $requeteSQLForList .= 'body REGEXP \''.$needle.'\'';
                    // add search in list
                    // $results is an array not empty only if list
                    foreach ($results as $result) {
                        $requeteSQLForList .= ' OR ';
                        if (!$result['isCheckBox']) {
                            $requeteSQLForList .= ' body LIKE \'%"'.str_replace('_', '\\_', $result['propertyName']).'":"'.$result['key'].'"%\'';
                        } else {
                            $requeteSQLForList .= ' body REGEXP \'"'.str_replace('_', '\\_', $result['propertyName']).'":(' .
                                '"'.$result['key'] . '"'.
                                '|"[^"]*,' . $result['key'] . '"'.
                                '|"' . $result['key'] . ',[^"]*"'.
                                '|"[^"]*,' .$result['key'] . ',[^"]*"'.
                                ')\'';
                        }
                    }
                    $requeteSQLForList .= ')';
                }
            }
        }
        if (!empty($requeteSQLForList)) {
            $requeteSQLForList = ' OR ('.$requeteSQLForList.') ';
        }
        
        // Modification de caractère spéciaux
        $phraseFormatted= str_replace(array('*', '?'), array('%', '_'), $searchText);
        $phraseFormatted = $this->dbService->escape($phraseFormatted);

        // TODO retrouver la facon d'afficher les commentaires (AFFICHER_COMMENTAIRES ? '':'AND tag NOT LIKE "comment%"').
        $requestfull = "SELECT body, tag FROM {$this->dbService->prefixTable('pages')} ".
            "WHERE latest = \"Y\" {$this->aclService->updateRequestWithACL()} ".
            "AND (body LIKE \"%{$phraseFormatted}%\"{$requeteSQLForList}) ORDER BY tag LIMIT 100";

        return compact('requestfull', 'needles');
    }

    private function displayNewSearchResult($string, $phrase, $needles = []): string
    {
        $string = strip_tags($string);
        $query = trim(str_replace(array("+","?","*"), array(" "," "," "), $phrase));
        $qt = explode(" ", $query);
        $num = count($qt);
        $cc = ceil(154 / $num);
        $string_re = '';
        foreach ($needles as $needle => $result) {
            if (preg_match('/'.$needle.'/i', $string, $matches)) {
                $tab = preg_split("/(".$matches[0].")/iu", $string, 2, PREG_SPLIT_DELIM_CAPTURE);
                if (count($tab)>1) {
                    $avant = strip_tags(mb_substr($tab[0], -$cc, $cc));
                    $apres = strip_tags(mb_substr($tab[2], 0, $cc));
                    $string_re .= '<p style="margin-top:0;margin-left:1rem;"><i style="color:silver;">[…]</i>' . $avant . '<b>' . $tab[1] . '</b>' . $apres . '<i style="color:silver;">[…]</i></p> ';
                }
            }
        }
        if (empty($string_re)) {
            for ($i = 0; $i < $num; $i++) {
                $tab[$i] = preg_split("/($qt[$i])/iu", $string, 2, PREG_SPLIT_DELIM_CAPTURE);
                if (count($tab[$i])>1) {
                    $avant[$i] = strip_tags(mb_substr($tab[$i][0], -$cc, $cc));
                    $apres[$i] = strip_tags(mb_substr($tab[$i][2], 0, $cc));
                    $string_re .= '<p style="margin-top:0;margin-left:1rem;"><i style="color:silver;">[…]</i>' . $avant[$i] . '<b>' . $tab[$i][1] . '</b>' . $apres[$i] . '<i style="color:silver;">[…]</i></p> ';
                }
            }
        }
        return $string_re;
    }
}
