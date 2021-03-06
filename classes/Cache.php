<?php
/**
 * LiteSpeed Cache for Prestashop
 *
 * NOTICE OF LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see https://opensource.org/licenses/GPL-3.0 .
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

use LiteSpeedCache as LSC;
use LiteSpeedCacheLog as LSLog;
use LiteSpeedCacheConfig as Conf;

class LiteSpeedCacheCore
{
    const LSHEADER_PURGE = 'X-Litespeed-Purge';
    const LSHEADER_CACHE_CONTROL = 'X-Litespeed-Cache-Control';
    const LSHEADER_CACHE_TAG = 'X-Litespeed-Tag';
    const LSHEADER_CACHE_VARY = 'X-Litespeed-Vary';

    private $cacheTags = array();
    private $purgeTags;
    private $config;
    private $esiTtl;
    private $curHeaders;

    public function __construct(LiteSpeedCacheConfig $config)
    {
        $this->config = $config;
        $this->purgeTags = array('pub' => array(), 'priv' => array());
        $this->curHeaders = array(self::LSHEADER_CACHE_CONTROL => '',
            self::LSHEADER_CACHE_TAG => '',
            self::LSHEADER_PURGE => '');
    }

    public function setEsiTtl($ttl)
    {
        $this->esiTtl = $ttl; // todo: may retire
    }

    public function isCacheableRoute($controllerType, $controllerClass)
    {
        $reason = '';
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $reason = 'Not GET request';
        } elseif ($controllerType != DispatcherCore::FC_FRONT) {
            $reason = 'Not FC_FRONT';
        } else {
            $tag = $this->config->isControllerCacheable($controllerClass);
            if ($tag === false) {
                $reason = 'Not in defined cacheable controllers';
            } elseif (!$this->inDoNotCacheRules($reason) && $tag) {
                $this->addCacheTags($tag);
            }
        }
        if ($reason) {
            $reason = 'Route not cacheable: ' . $controllerClass . ' - ' . $reason;
        } elseif (defined('_LITESPEED_DEBUG_') && _LITESPEED_DEBUG_ >= LSLog::LEVEL_CACHE_ROUTE) {
            LSLog::log('route in defined cacheable controllers ' . $controllerClass, LSLog::LEVEL_CACHE_ROUTE);
        }

        // check purge by controller
        if ($ptags = $this->config->isPurgeController($controllerClass)) {
            // usually won't happen for both pub and priv together
            if (!empty($ptags['pub'])) {
                $this->purgeByTags($ptags['pub'], false, $controllerClass);
            }
            if (!empty($ptags['priv'])) {
                $this->purgeByTags($ptags['priv'], true, $controllerClass);
            }
        }
        return $reason;
    }

    public function inDoNotCacheRules(&$reason)
    {
        $nocache = $this->config->getNoCacheConf();
        $requrl = $_SERVER['REQUEST_URI'];
        foreach ($nocache[Conf::CFG_NOCACHE_URL] as $url) {
            $url1 = rtrim($url, '*');
            if ($url1 !== $url) { // contains *
                if (strpos($requrl, $url1) !== false) {
                    $reason = 'disabled url (partial match) ' . $url;
                    return true;
                }
            } elseif ($url == $requrl) {
                $reason = 'disabled url (exact match) ' . $url;
                return true;
            }
        }
        foreach ($nocache[Conf::CFG_NOCACHE_VAR] as $var) {
            if (isset($_REQUEST[$var])) {
                $reason = 'contains param ' . $var;
                return true;
            }
        }
        return false;
    }

    public function hasNotification()
    {
        if (($smarty = Context::getContext()->smarty) !== null) {
            $notification = $smarty->getTemplateVars('notifications');
            if (is_array($notification)) {
                if (!empty($notification['error'])
                        || !empty($notification['warning'])
                        || !empty($notification['success'])
                        || !empty($notification['info'])) {
                    return true;
                }
            }
        }
        return false;
    }

    public function initCacheTagsByController($params)
    {
        if (!empty($this->cacheTags)) {
            return; // already initialized
        }
        if (!isset($params['controller'])) {
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_UNEXPECTED) {
                LSLog::log('initCacheTagsByController - no controller in param', LSLog::LEVEL_UNEXPECTED);
            }
            return;
        }
        $controller = $params['controller'];
        $tag = null;
        $entity = isset($params['entity']) ?
                $params['entity'] // PS 1.7
                : $controller->php_self; // PS 1.6

        switch ($entity) {
            case 'product':
                if (method_exists($controller, 'getProduct')
                        && ($p = $controller->getProduct()) != null) {
                    $tag = Conf::TAG_PREFIX_PRODUCT . $p->id;
                }
                break;
            case 'category':
                if (method_exists($controller, 'getCategory')
                        && ($c = $controller->getCategory()) != null) {
                    $tag = Conf::TAG_PREFIX_CATEGORY . $c->id;
                }
                break;
        }

        if (!$tag && isset($params['id'])) {
            $id = $params['id'];
            switch ($controller->php_self) {
                case 'product':
                    $tag = Conf::TAG_PREFIX_PRODUCT . $id;
                    break;
                case 'category':
                    $tag = Conf::TAG_PREFIX_CATEGORY . $id;
                    break;
                case 'manufacturer':
                    $tag = Conf::TAG_PREFIX_MANUFACTURER . $id;
                    break;
                case 'supplier':
                    $tag = Conf::TAG_PREFIX_SUPPLIER . $id;
                    break;
                case 'cms':
                    $tag = Conf::TAG_PREFIX_CMS . $id;
                    break;
            }
        }

        if ($tag) {
            $this->addCacheTags($tag);
        } elseif (_LITESPEED_DEBUG_ >= LSLog::LEVEL_UNEXPECTED) {
            LSLog::log('check what we have here - initCacheTagsByController', LSLog::LEVEL_UNEXPECTED);
        }
    }

    public function addCacheTags($tag)
    {
        $old = count($this->cacheTags);
        if (is_array($tag)) {
            $this->cacheTags = array_unique(array_merge($this->cacheTags, $tag));
        } elseif (!in_array($tag, $this->cacheTags)) {
            $this->cacheTags[] = $tag;
        }
        return (count($this->cacheTags) > $old);
    }

    // return 1: added, 0: already exists, 2: already has purgeall
    public function addPurgeTags($tag, $isPrivate)
    {
        $returnCode = 0;
        $type = $isPrivate ? 'priv' : 'pub';
        if (in_array('*', $this->purgeTags[$type])) {
            return 2;
        }

        if (is_array($tag)) {
            $oldcount = count($this->purgeTags[$type]);
            $this->purgeTags[$type] = array_unique(array_merge($this->purgeTags[$type], $tag));
            if (count($this->purgeTags[$type]) > $oldcount) {
                $returnCode = 1;
            }
        } elseif (!in_array($tag, $this->purgeTags[$type])) {
            $this->purgeTags[$type][] = $tag;
            $returnCode = 1;
        }

        if (in_array('*', $this->purgeTags[$type])) {
            $this->purgeTags[$type] = array('*'); // purge all
        }
        return $returnCode;
    }

    private function isNewPurgeTag($tag, $isPrivate)
    {
        $type = $isPrivate ? 'priv' : 'pub';
        if (in_array('*', $this->purgeTags['pub'])
            || in_array($tag, $this->purgeTags[$type])
            || ($isPrivate && in_array('*', $this->purgeTags[$type]))) {
            return false;
        } else {
            return true;
        }
    }

    public function purgeByTags($tags, $isPrivate, $from)
    {
        if ($from && _LITESPEED_DEBUG_ >= LSLog::LEVEL_PURGE_EVENT) {
            LSLog::log('purgeByTags from ' . $from, LSLog::LEVEL_PURGE_EVENT);
        }

        if ($this->addPurgeTags($tags, $isPrivate) == 1) {
            $this->setPurgeHeader();
        }
    }

    private function getPurgeTagsByProduct($id_product, $product, $isupdate)
    {
        $pid = Conf::TAG_PREFIX_PRODUCT . $id_product;
        if (!$this->isNewPurgeTag($pid, false)) {
            return null; // has purge all or already added
        }

        $tags = array();
        $tags['pub'] = $this->config->getDefaultPurgeTagsByProduct();
        $tags['pub'][] = $pid;
        if ($product === null) {
            // populate product
            $product = new Product((int) $id_product);
        }

        $tags['pub'][] = Conf::TAG_PREFIX_MANUFACTURER . $product->id_manufacturer;
        $tags['pub'][] = Conf::TAG_PREFIX_SUPPLIER . $product->id_supplier;
        if (!$isupdate) {
            // new or delete, also purge all suppliers and manufacturers list, as it shows # of products in it
            $tags['pub'][] = Conf::TAG_PREFIX_MANUFACTURER;
            $tags['pub'][] = Conf::TAG_PREFIX_SUPPLIER;
        }
        $cats = $product->getCategories();
        if (!empty($cats)) {
            foreach ($cats as $catid) {
                $tags['pub'][] = Conf::TAG_PREFIX_CATEGORY . $catid;
            }
        }
        return $tags;
    }

    private function getPurgeTagsByCategory($category)
    {
        $cid = Conf::TAG_PREFIX_CATEGORY . $category->id_category;
        if (!$this->isNewPurgeTag($cid, false)) {
            return null; // has purge all or already added
        }

        $tags = array();
        $tags['pub'] = $this->config->getDefaultPurgeTagsByCategory();
        $tags['pub'][] = $cid;

        if (!$category->is_root_category) {
            $cats = $category->getParentsCategories();
            if (!empty($cats)) {
                foreach ($cats as $catid) {
                    $tags['pub'][] = Conf::TAG_PREFIX_CATEGORY . $catid;
                }
            }
        }
        return $tags;
    }

    public function purgeByCatchAllMethod($method, $args)
    {
        $tags = $this->getPurgeTagsByHookMethod($method, $args);
        if (empty($tags)) {
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_UNEXPECTED) {
                LSLog::log('Unexpected hook function called ' . $method, LSLog::LEVEL_UNEXPECTED);
            }
            return;
        }

        $returnCode = 0;
        if (!empty($tags['pub'])) {
            $returnCode |= $this->addPurgeTags($tags['pub'], false);
        }
        if (!empty($tags['priv'])) {
            $returnCode |= $this->addPurgeTags($tags['priv'], true);
        }
        if (($returnCode & 1) == 1) { // added
            $this->setPurgeHeader();
        }
        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_PURGE_EVENT) {
            LSLog::log('purgeByCatchAll ' . $method . ' res=' . $returnCode, LSLog::LEVEL_PURGE_EVENT);
        }
    }

    private function getPurgeTagsByHookMethod($method, $args)
    {
        if (strncmp($method, 'hook', 4) != 0) {
            return null;
        }

        $event = Tools::strtolower(Tools::substr($method, 4));
        $tags = array();

        switch ($event) {
            case 'actioncustomerlogoutafter':
            case 'actionauthentication':
            case 'actioncustomeraccountadd':
                $tags['priv'] = array('*');
                break;

            case 'actionproductadd':
            case 'actionproductsave':
            case 'actionproductupdate':
            case 'actionproductdelete':
                return $this->getPurgeTagsByProduct($args['id_product'], $args['product'], false);
            case 'actionobjectspecificpricecoreaddafter':
            case 'actionobjectspecificpricecoredeleteafter':
                return $this->getPurgeTagsByProduct($args['object']->id_product, null, true);
            case 'actionwatermark':
                return $this->getPurgeTagsByProduct($args['id_product'], null, true);

            case 'categoryupdate':
            case 'actioncategoryupdate':
            case 'actioncategoryadd':
            case 'actioncategorydelete':
                return $this->getPurgeTagsByCategory($args['category']);

            case 'actionobjectcmsupdateafter':
            case 'actionobjectcmsdeleteafter':
            case 'actionobjectcmsaddafter':
                $tags['pub'] = array(Conf::TAG_PREFIX_CMS . $args['object']->id,
                    Conf::TAG_PREFIX_CMS, // cmscategory
                    Conf::TAG_SITEMAP);
                break;

            case 'actionobjectsupplierupdateafter':
            case 'actionobjectsupplierdeleteafter':
            case 'actionobjectsupplieraddafter':
                $tags['pub'] = array(Conf::TAG_PREFIX_SUPPLIER . $args['object']->id,
                Conf::TAG_PREFIX_SUPPLIER, // all supplier
                Conf::TAG_SITEMAP);
                break;

            case 'actionobjectmanufacturerupdateafter':
            case 'actionobjectmanufacturerdeleteafter':
            case 'actionobjectmanufactureraddAfter':
                $tags['pub'] = array(Conf::TAG_PREFIX_MANUFACTURER . $args['object']->id,
                    Conf::TAG_PREFIX_MANUFACTURER,
                    Conf::TAG_SITEMAP); // allbrands
                break;

            case 'actionobjectstoreupdateafter':
                $tags['pub'] = array(Conf::TAG_STORES);
                break;

            default: // custom defined events
                return $this->config->getPurgeTagsByEvent($event);
        }
        return $tags;
    }

    private function setPurgeHeader()
    {
        if (headers_sent() ||
                (count($this->purgeTags['pub']) == 0 &&
                count($this->purgeTags['priv']) == 0)) {
            return;
        }
        $purgeHeader = '';
        $pre = 'tag=' . LiteSpeedCacheHelper::getTagPrefix();
        $clearInternal = false;

        if (in_array('*', $this->purgeTags['pub'])) {
            // when purge all public, also purge all private block
            $purgeHeader .= $pre . ',' . $pre . '_PRIV';
            $clearInternal = true;
        } else {
            $pre .= '_';
            if (count($this->purgeTags['pub'])) {
                $purgeHeader .= $pre . implode(",$pre", $this->purgeTags['pub']);
            }
            if (count($this->purgeTags['priv'])) {
                if ($purgeHeader) {
                    $purgeHeader .= ';'; // has public
                }
                $purgeHeader .= 'private,';
                if (in_array('*', $this->purgeTags['priv'])) {
                    $purgeHeader .= '*';
                } else {
                    $purgeHeader .= $pre . implode(",$pre", $this->purgeTags['priv']);
                }
            }
        }

        if ($purgeHeader
                && ($purgeHeader != $this->curHeaders[self::LSHEADER_PURGE])
                && !headers_sent()) {
            $this->curHeaders[self::LSHEADER_PURGE] = $purgeHeader;
            $purgeHeader = self::LSHEADER_PURGE . ': ' . $purgeHeader;
            header($purgeHeader);   // due to ajax call, always set header on the event
            if ($clearInternal) {
                LiteSpeedCacheHelper::clearInternalCache();
            }
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_SETHEADER) {
                LSLog::log('Set header ' . $purgeHeader, LSLog::LEVEL_SETHEADER);
            }
        }
    }

    public function purgeEntireStorage($from)
    {
        $purgeHeader = self::LSHEADER_PURGE . ': *';
        header($purgeHeader);
        LSLog::log('Set header ' . $purgeHeader . ' (' . $from . ')', LSLog::LEVEL_FORCE);
    }

    public function setCacheControlHeader($from)
    {
        if (headers_sent()) {
            return;
        }

        $this->setPurgeHeader();

        $cacheControlHeader = '';
        $ccflag = LSC::getCCFlag();
        $dbgMesg = '';

        if ((($ccflag & LSC::CCBM_NOT_CACHEABLE) == 0) && (($ccflag & LSC::CCBM_CACHEABLE) != 0)) {
            $prefix = LiteSpeedCacheHelper::getTagPrefix();
            $tags = array();
            if (($ccflag & LSC::CCBM_ESI_REQ) != 0) {
                $ttl = $this->esiTtl;
            } elseif (($ccflag & LSC::CCBM_PRIVATE) != 0) {
                $ttl = $this->config->get(Conf::CFG_PRIVATE_TTL);
            } else {
                $ttl = $this->config->get(Conf::CFG_PUBLIC_TTL);
            }

            if (($ccflag & LSC::CCBM_PRIVATE) == 0) {
                $cacheControlHeader .= 'public,max-age=' . $ttl;
                $tags[] = $prefix; // for purge all PS cache
                $shopId = Context::getContext()->shop->id; // todo: should have in env
                $tags[] = $prefix . '_' . Conf::TAG_PREFIX_SHOP . $shopId; // for purge one shop
            } else {
                $cacheControlHeader .= 'private,no-vary,max-age=' . $ttl;
                $tags[] = 'public:' . $prefix . '_PRIV'; // in private cache, use public:tag_name_PRIV
            }

            foreach ($this->cacheTags as $tag) {
                $tags[] = $prefix . '_' . $tag;
            }

            $cacheTagHeader = self::LSHEADER_CACHE_TAG . ': ' . implode(',', $tags);
            if ($cacheTagHeader != $this->curHeaders[self::LSHEADER_CACHE_TAG]) {
                $this->curHeaders[self::LSHEADER_CACHE_TAG] = $cacheTagHeader;
                header($cacheTagHeader);
                $dbgMesg .= 'Set header ' . $cacheTagHeader . "\n";
            }
        } else {
            $cacheControlHeader = 'no-cache';
        }
        if (($ccflag & LSC::CCBM_ESI_ON) != 0) {
            $cacheControlHeader .= ',esi=on';
        }
        if ($cacheControlHeader != $this->curHeaders[self::LSHEADER_CACHE_CONTROL]) {
            $this->curHeaders[self::LSHEADER_CACHE_CONTROL] = $cacheControlHeader;
            $cacheControlHeader = self::LSHEADER_CACHE_CONTROL . ': ' . $cacheControlHeader;
            header($cacheControlHeader);
            $dbgMesg .= 'Set header ' . $cacheControlHeader;
        }
        if ($dbgMesg && defined('_LITESPEED_DEBUG_') && _LITESPEED_DEBUG_ >= LSLog::LEVEL_SETHEADER) {
            LSLog::log($dbgMesg . ' from ' . $from, LSLog::LEVEL_SETHEADER);
        }
    }
}
