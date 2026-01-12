<?php

namespace app\api\controller;

use Throwable;
use app\common\controller\Frontend;
use think\exception\HttpResponseException;

use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("易盾OCR认证")]
class YidunOcr extends Frontend
{
    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("身份证OCR识别测试"),
        Apidoc\Tag("易盾,OCR,身份证识别"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/YidunOcr/ocrCheck"),
        Apidoc\Param(name:"picType",type: "int",require: true,desc: "图片类型",example:"1",values:"1,2"),
        Apidoc\Param(name:"frontPicture",type: "string",require: false,desc: "身份证正面URL或BASE64",example:""),
        Apidoc\Param(name:"backPicture",type: "string",require: false,desc: "身份证背面URL或BASE64",example:""),
        Apidoc\Param(name:"dataId",type: "string",require: false,desc: "数据标识，可空",example:""),
        Apidoc\Returned("code",type: "int",desc: "响应码"),
        Apidoc\Returned("msg",type: "string",desc: "响应消息"),
        Apidoc\Returned("result",type: "object",desc: "识别结果"),
        Apidoc\Returned("result.status",type: "int",desc: "识别状态(1=成功,其他=失败)"),
        Apidoc\Returned("result.statusDesc",type: "string",desc: "状态说明"),
        Apidoc\Returned("result.reasonTypeDesc",type: "string",desc: "失败原因说明(失败时存在)"),
    ]
    /**
     * 身份证OCR识别测试
     * @throws Throwable
     */
    public function ocrCheck(): void
    {
        try {
            if (!$this->request->isPost()) {
                $this->error('仅支持POST请求');
            }

            $picType       = (int)$this->request->post('picType', 0);
            $frontPicture  = (string)$this->request->post('frontPicture', '');
            $backPicture   = (string)$this->request->post('backPicture', '');
            $dataId        = (string)$this->request->post('dataId', '');

            $ocr     = new \app\common\library\YidunOcr();
            $result  = $ocr->check($picType, $frontPicture, $backPicture, $dataId);

            $this->success('', $result);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("实人认证测试"),
        Apidoc\Tag("易盾,实人认证,身份验证"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/YidunOcr/realPersonCheck"),
        Apidoc\Param(name:"name",type: "string",require: true,desc: "用户真实姓名",example:"张三"),
        Apidoc\Param(name:"cardNo",type: "string",require: true,desc: "身份证号码(18位)",example:"341622123456784317"),
        Apidoc\Param(name:"picType",type: "int",require: true,desc: "图片类型",example:"1",values:"1,2"),
        Apidoc\Param(name:"avatar",type: "string",require: true,desc: "用户正面头像照URL或BASE64",example:""),
        Apidoc\Param(name:"dataId",type: "string",require: false,desc: "数据标识，可空",example:""),
        Apidoc\Param(name:"encryptType",type: "string",require: false,desc: "加密方式(3:SM4, 4:AES)",example:""),
        Apidoc\Returned("code",type: "int",desc: "响应码"),
        Apidoc\Returned("msg",type: "string",desc: "响应消息"),
        Apidoc\Returned("result",type: "object",desc: "认证结果"),
        Apidoc\Returned("result.status",type: "int",desc: "认证状态(1=通过,2=不通过,0=待定)"),
        Apidoc\Returned("result.statusDesc",type: "string",desc: "状态说明"),
        Apidoc\Returned("result.faceMatched",type: "int",desc: "人脸比对结果(1=通过,2=不通过,0=不确定)"),
        Apidoc\Returned("result.similarityScore",type: "float",desc: "相似度得分(0-1)"),
        Apidoc\Returned("result.reasonTypeDesc",type: "string",desc: "失败原因说明(失败时存在)"),
    ]
    /**
     * 实人认证测试
     * @throws Throwable
     */
    public function realPersonCheck(): void
    {
        try {
            if (!$this->request->isPost()) {
                $this->error('仅支持POST请求');
            }

            $name        = (string)$this->request->post('name', '');
            $cardNo      = (string)$this->request->post('cardNo', '');
            $picType     = (int)$this->request->post('picType', 0);
            $avatar      = (string)$this->request->post('avatar', '');
            $dataId      = (string)$this->request->post('dataId', '');
            $encryptType = (string)$this->request->post('encryptType', '');

            $ocr     = new \app\common\library\YidunOcr();
            $result  = $ocr->realPersonCheck($name, $cardNo, $picType, $avatar, $dataId, $encryptType);

            $this->success('', $result);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("交互式人脸核身测试"),
        Apidoc\Tag("易盾,人脸核身,活体检测"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/YidunOcr/livePersonCheck"),
        Apidoc\Param(name:"name",type: "string",require: true,desc: "用户真实姓名",example:"张三"),
        Apidoc\Param(name:"cardNo",type: "string",require: true,desc: "身份证号码(18位或15位)",example:"341622123456784317"),
        Apidoc\Param(name:"token",type: "string",require: true,desc: "SDK认证通过的token",example:""),
        Apidoc\Param(name:"needAvatar",type: "string",require: false,desc: "是否需要返回正面照(true/false)",example:"false"),
        Apidoc\Param(name:"picType",type: "int",require: false,desc: "图片类型",example:"1",values:"1,2"),
        Apidoc\Param(name:"dataId",type: "string",require: false,desc: "数据标识，可空",example:""),
        Apidoc\Returned("code",type: "int",desc: "响应码"),
        Apidoc\Returned("msg",type: "string",desc: "响应消息"),
        Apidoc\Returned("result",type: "object",desc: "核身结果"),
        Apidoc\Returned("result.status",type: "int",desc: "核身状态(1=通过,2=不通过,0=待定)"),
        Apidoc\Returned("result.statusDesc",type: "string",desc: "状态说明"),
        Apidoc\Returned("result.faceMatched",type: "int",desc: "人脸比对结果(1=通过,2=不通过,0=不确定)"),
        Apidoc\Returned("result.similarityScore",type: "float",desc: "相似度得分(0-1)"),
        Apidoc\Returned("result.reasonTypeDesc",type: "string",desc: "失败原因说明(失败时存在)"),
    ]
    /**
     * 交互式人脸核身测试
     * @throws Throwable
     */
    public function livePersonCheck(): void
    {
        try {
            if (!$this->request->isPost()) {
                $this->error('仅支持POST请求');
            }

            $name        = (string)$this->request->post('name', '');
            $cardNo      = (string)$this->request->post('cardNo', '');
            $token       = (string)$this->request->post('token', '');
            $needAvatar  = (string)$this->request->post('needAvatar', '');
            $picType     = $this->request->post('picType', null);
            $dataId      = (string)$this->request->post('dataId', '');

            $picTypeInt = null;
            if ($picType !== null && $picType !== '') {
                $picTypeInt = (int)$picType;
            }

            $ocr    = new \app\common\library\YidunOcr();
            $result = $ocr->livePersonCheck($name, $cardNo, $token, $needAvatar, $picTypeInt, $dataId);

            $this->success('', $result);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("单活体检测测试"),
        Apidoc\Tag("易盾,活体检测"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/YidunOcr/livePersonRecheck"),
        Apidoc\Param(name:"token",type: "string",require: true,desc: "SDK认证通过的token",example:""),
        Apidoc\Param(name:"needAvatar",type: "string",require: false,desc: "是否需要返回正面照(true/false)",example:"false"),
        Apidoc\Param(name:"picType",type: "int",require: false,desc: "图片类型",example:"1",values:"1,2"),
        Apidoc\Param(name:"dataId",type: "string",require: false,desc: "数据标识，可空",example:""),
        Apidoc\Returned("code",type: "int",desc: "响应码"),
        Apidoc\Returned("msg",type: "string",desc: "响应消息"),
        Apidoc\Returned("result",type: "object",desc: "检测结果"),
        Apidoc\Returned("result.lpCheckStatus",type: "int",desc: "活体检测状态(1=通过,2=不通过,0=待定)"),
        Apidoc\Returned("result.lpCheckStatusDesc",type: "string",desc: "状态说明"),
        Apidoc\Returned("result.reasonTypeDesc",type: "string",desc: "失败原因说明(失败时存在)"),
    ]
    /**
     * 单活体检测测试
     * @throws Throwable
     */
    public function livePersonRecheck(): void
    {
        try {
            if (!$this->request->isPost()) {
                $this->error('仅支持POST请求');
            }

            $token      = (string)$this->request->post('token', '');
            $needAvatar = (string)$this->request->post('needAvatar', '');
            $picType    = $this->request->post('picType', null);
            $dataId     = (string)$this->request->post('dataId', '');

            $picTypeInt = null;
            if ($picType !== null && $picType !== '') {
                $picTypeInt = (int)$picType;
            }

            $ocr    = new \app\common\library\YidunOcr();
            $result = $ocr->livePersonRecheck($token, $needAvatar, $picTypeInt, $dataId);

            $this->success('', $result);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("H5人脸核身authToken校验"),
        Apidoc\Tag("易盾,H5人脸核身"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/YidunOcr/h5Recheck"),
        Apidoc\Param(name:"authToken",type: "string",require: true,desc: "认证Token（从H5页面返回）",example:""),
        Apidoc\Returned("code",type: "int",desc: "响应码"),
        Apidoc\Returned("msg",type: "string",desc: "响应消息"),
        Apidoc\Returned("data",type: "object",desc: "核身结果"),
        Apidoc\Returned("data.taskId",type: "string",desc: "任务ID"),
        Apidoc\Returned("data.picType",type: "int",desc: "图片类型"),
        Apidoc\Returned("data.avatar",type: "string",desc: "头像URL"),
        Apidoc\Returned("data.status",type: "int",desc: "核身状态(1=通过,2=不通过,0=待定)"),
        Apidoc\Returned("data.reasonType",type: "int",desc: "失败原因类型"),
        Apidoc\Returned("data.isPayed",type: "int",desc: "是否付费"),
        Apidoc\Returned("data.similarityScore",type: "float",desc: "相似度得分(0-1)"),
        Apidoc\Returned("data.faceMatched",type: "int",desc: "人脸比对结果(1=通过,2=不通过,0=不确定)"),
        Apidoc\Returned("data.faceAttributeInfo",type: "object",desc: "人脸属性信息"),
        Apidoc\Returned("data.extInfo",type: "object",desc: "扩展信息"),
    ]
    /**
     * H5人脸核身authToken校验
     * @throws Throwable
     */
    public function h5Recheck(): void
    {
        try {
            if (!$this->request->isPost()) {
                $this->error('仅支持POST请求');
            }

            $authToken = (string)$this->request->post('authToken', '');

            if (empty($authToken)) {
                $this->error('authToken不能为空');
            }

            $ocr    = new \app\common\library\YidunOcr();
            $result = $ocr->h5Recheck($authToken);

            // 返回易盾的原始结果，前端根据result中的status和faceMatched判断
            if (isset($result['result'])) {
                $this->success('ok', $result['result']);
            } else {
                $this->error('核身结果解析失败');
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }
}

