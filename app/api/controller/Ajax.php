<?php

namespace app\api\controller;

use Throwable;
use think\Response;
use app\common\library\Upload;
use app\common\controller\Frontend;
use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("Ajax通用接口")]
class Ajax extends Frontend
{
    protected array $noNeedLogin = ['area', 'buildSuffixSvg'];

    protected array $noNeedPermission = ['upload'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("文件/图片上传"),
        Apidoc\Tag("文件上传,图片上传,upload"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/ajax/upload"),
        Apidoc\Param(name:"file", type: "file", require: true, desc: "上传的文件（支持格式：jpg,png,bmp,jpeg,gif,webp,zip,rar,wav,mp4,mp3）"),
        Apidoc\Param(name:"driver", type: "string", require: false, default: "local", desc: "存储驱动（local=本地存储）"),
        Apidoc\Param(name:"topic", type: "string", require: false, default: "default", desc: "存储子目录/分类"),
        Apidoc\Returned("id", type: "int", desc: "附件ID"),
        Apidoc\Returned("url", type: "string", desc: "文件访问路径", mock: "/storage/default/20231113/demo_abc123.jpg"),
        Apidoc\Returned("name", type: "string", desc: "原始文件名", mock: "demo.jpg"),
        Apidoc\Returned("size", type: "int", desc: "文件大小（字节）", mock: "102400"),
        Apidoc\Returned("mimetype", type: "string", desc: "文件MIME类型", mock: "image/jpeg"),
        Apidoc\Returned("width", type: "int", desc: "图片宽度（仅图片有值）", mock: "1920"),
        Apidoc\Returned("height", type: "int", desc: "图片高度（仅图片有值）", mock: "1080"),
        Apidoc\Returned("sha1", type: "string", desc: "文件SHA1值", mock: "da39a3ee5e6b4b0d3255bfef95601890afd80709"),
        Apidoc\Returned("storage", type: "string", desc: "存储方式", mock: "local"),
    ]
    public function upload(): void
    {
        $file   = $this->request->file('file');
        $driver = $this->request->param('driver', 'local');
        $topic  = $this->request->param('topic', 'default');
        try {
            $upload     = new Upload();
            $attachment = $upload
                ->setFile($file)
                ->setDriver($driver)
                ->setTopic($topic)
                ->upload(null, 0, $this->auth->id);
            unset($attachment['create_time'], $attachment['quote']);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }

        $this->success(__('File uploaded successfully'), [
            'file' => $attachment ?? []
        ]);
    }

    /**
     * 省份地区数据
     * @throws Throwable
     */
    public function area(): void
    {
        $this->success('', get_area());
    }

    public function buildSuffixSvg(): Response
    {
        $suffix     = $this->request->param('suffix', 'file');
        $background = $this->request->param('background');
        $content    = build_suffix_svg((string)$suffix, (string)$background);
        return response($content, 200, ['Content-Length' => strlen($content)])->contentType('image/svg+xml');
    }
}