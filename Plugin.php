<?php

namespace TypechoPlugin\LZStat;

use Typecho\Common;
use Typecho\Date;
use Typecho\Db;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Radio;
use Utils\Helper;
use Widget\Archive;
use Widget\Options;
use Widget\User;

/**
 * 对浏览量和点赞量进行统计，并且实现加权排序
 * 权重：点赞量*100 + 浏览量
 * 
 * @package LZStat 
 * @author laozhu
 * @version 1.3.0
 * @link https://ilaozhu.com/archives/2068/
 */
class Plugin implements PluginInterface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho\Plugin\Exception
     */
    public static function activate()
    {
        // viewsNum: 浏览量 likesNum: 点赞量
        self::ensureStatFields(['viewsNum' => '浏览次数', 'likesNum' => '点赞次数']);
        $archive = \Typecho\Plugin::factory(Archive::class);

        $archive->beforeRender = __CLASS__ . '::addViews';
        $archive->select = __CLASS__ . '::selectHandler';
        $archive->footer = __CLASS__ . '::footer';
        Helper::addAction('stat', Action::class);
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho\Plugin\Exception
     */
    public static function deactivate()
    {
        Helper::removeAction('stat');
    }

    /**
     * 获取插件配置面板
     *
     * @param Form $form 配置面板
     */
    public static function config(Form $form)
    {
        /** 文章列表排序 */
        $orderBy = new Radio(
            'orderBy',
            [
                'created'    => _t('创建时间'),
                'viewsNum'    => _t('浏览量'),
                'likesNum'    => _t('点赞量'),
                'weight'     => _t('加权排序')
            ],
            'created',
            _t('排序方式'),
            _t('文章列表会根据选中的方式降序排序，其中，权重计算规则是：点赞量*100 + 浏览量')
        );
        $form->addInput($orderBy);

        /** 侧边栏榜单文章排序 */
        $topOrder = new Radio(
            'topOrder',
            [
                'created'    => _t('创建时间'),
                'viewsNum'    => _t('浏览量'),
                'likesNum'    => _t('点赞量'),
                'weight'     => _t('加权排序')
            ],
            'created',
            _t('榜单文章'),
            _t('设置侧边栏文章榜单排序方式，规则同上')
        );
        $form->addInput($topOrder);

        $showText = new Radio(
            'showText',
            [
                0    => _t('否'),
                1    => _t('是')
            ],
            1,
            _t('显示正文'),
            _t('如果文章列表中需要显示文章正文，请选择是，否则请选择否')
        );
        $form->addInput($showText);
    }

    /**
     * 个人用户的配置面板
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form) {}

    public static function addViews($archive)
    {
        if ($archive->is('post') || $archive->is('page')) {
            Action::updateViews($archive->cid);
        }
    }

    public static function selectHandler(Archive $archive)
    {
        $user = Widget::widget(User::class);
        $plugin = Widget::widget(Options::class)->plugin('LZStat');
        if ('post' == $archive->parameter->type || 'page' == $archive->parameter->type) {
            $select = $archive->select('table.contents.*', '(likesNum * 100 + viewsNum) AS weight')
                ->from('table.contents');
            if ($user->hasLogin()) {
                $select->where(
                    'table.contents.status = ? OR table.contents.status = ? 
                        OR (table.contents.status = ? AND table.contents.authorId = ?)',
                    'publish',
                    'hidden',
                    'private',
                    $user->uid
                );
            } else {
                $select->where(
                    'table.contents.status = ? OR table.contents.status = ?',
                    'publish',
                    'hidden'
                );
            }
        } else {
            if ($plugin->showText) {
                $select = $archive->select('table.contents.*', '(likesNum * 100 + viewsNum) AS weight');
            } else {
                $select = $archive->select(
                    'table.contents.cid',
                    'table.contents.title',
                    'table.contents.slug',
                    'table.contents.created',
                    'table.contents.modified',
                    'table.contents.type',
                    'table.contents.status',
                    'table.contents.commentsNum',
                    'table.contents.allowComment',
                    'table.contents.allowPing',
                    'table.contents.allowFeed',
                    'table.contents.template',
                    'table.contents.password',
                    'table.contents.authorId',
                    'table.contents.parent',
                    'table.contents.viewsNum',
                    'table.contents.likesNum',
                    '(table.contents.likesNum * 100 + table.contents.viewsNum) AS weight'
                );
            }

            $select->from('table.contents');
            if ($user->hasLogin()) {
                $select->where(
                    'table.contents.status = ? OR (table.contents.status = ? AND table.contents.authorId = ?)',
                    'publish',
                    'private',
                    $user->uid
                );
            } else {
                $select->where('table.contents.status = ?', 'publish');
            }
        }

        $select->where('table.contents.created < ?', Date::time());
        if ($plugin->orderBy == 'weight') {
            // typecho获取文章总数的逻辑有bug，为了不修改源码，暂时只能这么实现
            $select->order('likesNum * 100 + viewsNum', Db::SORT_DESC);
        } else {
            $select->order($plugin->orderBy, Db::SORT_DESC);
        }
        return $select;
    }

    public static function footer()
    {
        echo <<<EOF
        <script>
            let delay = false;
            document.addEventListener('click', function (e) {
                statHandler(e, 'likes');
            }, true);

            function statHandler(event, type) {
                if (!event.target.classList.contains('set-' + type)) {
                    return;
                }
                event.stopPropagation();
                if (delay) {  
                    event.preventDefault();
                    return;  
                }  
                delay = true;
                const cid = event.target.dataset.cid;
                const xhr = new XMLHttpRequest();
                xhr.open('GET', '/index.php/action/stat?do=' + type + '&cid='+cid, true);
                xhr.onload = function () {  
                    if (xhr.status >= 200 && xhr.status < 300) {  
                        const data = JSON.parse(xhr.responseText); 
                        const gets = document.querySelector('.get-' + type + '[data-cid="'+cid+'"]');
                        if (gets) {
                            gets.textContent = data.total;
                        } 
                    } else {  
                        console.error('请求失败: ' + xhr.statusText);  
                    } 
                    delay = false; 
                };  
                xhr.send();
            }
        </script>
        EOF;
    }

    /**
     * 获取榜单
     * 
     * @param string $orderBy 排序方式(created,viewsNum,likesNum,weight)，为空则根据配置排序
     */
    public static function getRank(string $orderBy = null)
    {
        if (!$orderBy) {
            $plugin = Widget::widget(Options::class)->plugin('LZStat');
            $orderBy = $plugin->topOrder;
        }

        if ($orderBy == 'created') {
            $title = _t('最新');
        } else {
            $title = _t('热门');
        }

        Rank::alloc(['orderBy' => $orderBy])->to($posts);
        return [
            'title' => $title,
            'orderBy' => $orderBy,
            'posts' => $posts
        ];
    }

    /**
     * 确保统计字段在数据表中
     * 
     * @param array $fields 字段列表，格式：'字段名' => '备注'
     */
    private static function ensureStatFields(array $fields)
    {
        if (empty($fields)) {
            return;
        }

        $db = Db::get();
        $isSqlite = false;
        if (strstr($db->getAdapterName(), "SQLite")) {
            $isSqlite = true;
        }
        $tableName = $db->getPrefix() . 'contents';
        foreach ($fields as $key => $value) {
            $sql = "";
            if ($isSqlite) {
                $sql = "select * from sqlite_master where name='$tableName' and sql like '%$key%'";
            } else {
                $sql = "SHOW COLUMNS FROM $tableName WHERE Field = '$key'";
            }
            $result = $db->query($sql);
            if ($result->rowCount() == 0) {
                if ($isSqlite) {
                    $db->query("ALTER TABLE $tableName ADD $key INT UNSIGNED NOT NULL DEFAULT '0'");
                } else {
                    $db->query("ALTER TABLE $tableName ADD $key INT UNSIGNED NOT NULL COMMENT '$value' DEFAULT '0'");
                }
            }
        }
    }
}
