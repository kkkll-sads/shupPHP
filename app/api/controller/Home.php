<?php

namespace app\api\controller;

use think\facade\Db;
use app\common\controller\Frontend;

class Home extends Frontend
{
    protected array $noNeedLogin = ['index', 'newsList', 'newsDetail', 'videoList'];

    public function initialize(): void
    {
        parent::initialize();
    }

    public function index(): void
    {
        $promoVideo = Db::name('content_media')
            ->where('category', 'promo_video')
            ->where('status', '1')
            ->order('sort desc,id desc')
            ->find();
        $promoVideo = $promoVideo ? $this->formatMedia($promoVideo) : null;

        $resourceList = Db::name('content_media')
            ->where('category', 'resource')
            ->where('status', '1')
            ->order('sort desc,id desc')
            ->limit(6)
            ->select()
            ->toArray();
        $resourceList = array_map(function ($item) {
            return $this->formatMedia($item);
        }, $resourceList);

        $hotNews = Db::name('content_news')
            ->where('status', '1')
            ->where('is_hot', 1)
            ->order('sort desc,id desc')
            ->limit(8)
            ->select()
            ->toArray();
        $hotNews = array_map(function ($item) {
            return $this->formatNews($item);
        }, $hotNews);

        $hotVideos = Db::name('content_media')
            ->where('category', 'hot_video')
            ->where('status', '1')
            ->order('sort desc,id desc')
            ->limit(6)
            ->select()
            ->toArray();
        $hotVideos = array_map(function ($item) {
            return $this->formatMedia($item);
        }, $hotVideos);

        $this->success('', [
            'promoVideo' => $promoVideo,
            'resources' => $resourceList,
            'hotNews' => $hotNews,
            'hotVideos' => $hotVideos,
        ]);
    }

    public function newsList(): void
    {
        $page = max(1, (int)$this->request->get('page/d', 1));
        $limit = max(1, min(20, (int)$this->request->get('limit/d', 10)));

        $paginator = Db::name('content_news')
            ->where('status', '1')
            ->order('publish_time desc,id desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page,
            ]);

        $list = array_map(function ($item) {
            return $this->formatNews($item);
        }, $paginator->items());

        $this->success('', [
            'list' => $list,
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    public function newsDetail(): void
    {
        $id = $this->request->get('id/d', 0);
        if ($id <= 0) {
            $this->error('资讯不存在');
        }

        $news = Db::name('content_news')->where('id', $id)->where('status', '1')->find();
        if (!$news) {
            $this->error('资讯不存在');
        }

        Db::name('content_news')->where('id', $id)->inc('view_count')->update();

        $this->success('', [
            'detail' => $this->formatNews($news),
        ]);
    }

    public function videoList(): void
    {
        $page = max(1, (int)$this->request->get('page/d', 1));
        $limit = max(1, min(20, (int)$this->request->get('limit/d', 6)));
        $category = $this->request->get('category', 'hot_video');

        $paginator = Db::name('content_media')
            ->where('status', '1')
            ->where('category', $category)
            ->order('sort desc,id desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page,
            ]);

        $list = array_map(function ($item) {
            return $this->formatMedia($item);
        }, $paginator->items());

        $this->success('', [
            'list' => $list,
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    protected function formatMedia(array $item): array
    {
        $item['cover_image'] = $item['cover_image'] ? full_url($item['cover_image'], false) : null;
        $item['media_url'] = $item['media_url'] ? full_url($item['media_url'], false) : null;
        $item['create_time'] = (int)$item['create_time'];
        $item['update_time'] = (int)$item['update_time'];
        return $item;
    }

    protected function formatNews(array $item): array
    {
        $item['cover_image'] = $item['cover_image'] ? full_url($item['cover_image'], false) : null;
        $item['publish_time'] = (int)$item['publish_time'];
        $item['publish_time_text'] = $item['publish_time'] ? date('Y-m-d', (int)$item['publish_time']) : '';
        $item['content'] = $item['content'] ?? '';
        $item['create_time'] = (int)$item['create_time'];
        $item['update_time'] = (int)$item['update_time'];
        return $item;
    }
}


