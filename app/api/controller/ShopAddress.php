<?php

namespace app\api\controller;

use think\facade\Db;
use app\common\controller\Frontend;
use hg\apidoc\annotation as Apidoc;
use think\exception\HttpResponseException;

#[Apidoc\Title("收货地址管理")]
class ShopAddress extends Frontend
{
    protected array $noNeedLogin = [];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("地址列表"),
        Apidoc\Tag("商城,地址"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/shopAddress/index"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Returned("list", type: "array", desc: "地址列表"),
    ]
    public function index(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $list = Db::name('shop_address')
            ->where('user_id', $this->auth->id)
            ->order('is_default desc, id desc')
            ->select()
            ->toArray();

        $this->success('', [
            'list' => $list,
        ]);
    }

    #[
        Apidoc\Title("添加地址"),
        Apidoc\Tag("商城,地址"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/shopAddress/add"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Param(name: "name", type: "string", require: true, desc: "收货人姓名"),
        Apidoc\Param(name: "phone", type: "string", require: true, desc: "手机号"),
        Apidoc\Param(name: "province", type: "string", require: false, desc: "省份"),
        Apidoc\Param(name: "city", type: "string", require: false, desc: "城市"),
        Apidoc\Param(name: "district", type: "string", require: false, desc: "区/县"),
        Apidoc\Param(name: "address", type: "string", require: true, desc: "详细地址"),
        Apidoc\Param(name: "is_default", type: "string", require: false, desc: "是否默认地址: 0=否, 1=是", default: "0"),
        Apidoc\Returned("id", type: "int", desc: "地址ID"),
    ]
    public function add(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $name = $this->request->param('name', '');
        $phone = $this->request->param('phone', '');
        $province = $this->request->param('province', '');
        $city = $this->request->param('city', '');
        $district = $this->request->param('district', '');
        $address = $this->request->param('address', '');
        $isDefault = $this->request->param('is_default', '0');

        if (!$name) {
            $this->error('收货人姓名不能为空');
        }

        if (!$phone) {
            $this->error('手机号不能为空');
        }

        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            $this->error('手机号格式不正确');
        }

        if (!$address) {
            $this->error('详细地址不能为空');
        }

        Db::startTrans();
        try {
            // 如果设为默认地址，先取消其他默认地址
            if ($isDefault == '1') {
                Db::name('shop_address')
                    ->where('user_id', $this->auth->id)
                    ->update(['is_default' => '0']);
            }

            $id = Db::name('shop_address')->insertGetId([
                'user_id' => $this->auth->id,
                'name' => $name,
                'phone' => $phone,
                'province' => $province,
                'city' => $city,
                'district' => $district,
                'address' => $address,
                'is_default' => $isDefault,
                'create_time' => time(),
                'update_time' => time(),
            ]);

            Db::commit();
            $this->success('添加成功', ['id' => $id]);
        } catch (HttpResponseException $e) {
            // success() 和 error() 抛出的 HttpResponseException，直接向上抛出，不做处理
            throw $e;
        } catch (\Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("编辑地址"),
        Apidoc\Tag("商城,地址"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/shopAddress/edit"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Param(name: "id", type: "int", require: true, desc: "地址ID"),
        Apidoc\Param(name: "name", type: "string", require: true, desc: "收货人姓名"),
        Apidoc\Param(name: "phone", type: "string", require: true, desc: "手机号"),
        Apidoc\Param(name: "province", type: "string", require: false, desc: "省份"),
        Apidoc\Param(name: "city", type: "string", require: false, desc: "城市"),
        Apidoc\Param(name: "district", type: "string", require: false, desc: "区/县"),
        Apidoc\Param(name: "address", type: "string", require: true, desc: "详细地址"),
        Apidoc\Param(name: "is_default", type: "string", require: false, desc: "是否默认地址: 0=否, 1=是"),
    ]
    public function edit(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $id = $this->request->param('id/d', 0);
        $name = $this->request->param('name', '');
        $phone = $this->request->param('phone', '');
        $province = $this->request->param('province', '');
        $city = $this->request->param('city', '');
        $district = $this->request->param('district', '');
        $address = $this->request->param('address', '');
        $isDefault = $this->request->param('is_default', '0');

        if (!$id) {
            $this->error('参数错误');
        }

        if (!$name) {
            $this->error('收货人姓名不能为空');
        }

        if (!$phone) {
            $this->error('手机号不能为空');
        }

        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            $this->error('手机号格式不正确');
        }

        if (!$address) {
            $this->error('详细地址不能为空');
        }

        Db::startTrans();
        try {
            $exist = Db::name('shop_address')
                ->where('id', $id)
                ->where('user_id', $this->auth->id)
                ->find();

            if (!$exist) {
                throw new \Exception('地址不存在');
            }

            // 如果设为默认地址，先取消其他默认地址
            if ($isDefault == '1') {
                Db::name('shop_address')
                    ->where('user_id', $this->auth->id)
                    ->where('id', '<>', $id)
                    ->update(['is_default' => '0']);
            }

            Db::name('shop_address')
                ->where('id', $id)
                ->update([
                    'name' => $name,
                    'phone' => $phone,
                    'province' => $province,
                    'city' => $city,
                    'district' => $district,
                    'address' => $address,
                    'is_default' => $isDefault,
                    'update_time' => time(),
                ]);

            Db::commit();
            $this->success('修改成功');
        } catch (HttpResponseException $e) {
            // success() 和 error() 抛出的 HttpResponseException，直接向上抛出，不做处理
            throw $e;
        } catch (\Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("删除地址"),
        Apidoc\Tag("商城,地址"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/shopAddress/delete"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Param(name: "id", type: "int", require: true, desc: "地址ID"),
    ]
    public function delete(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('参数错误');
        }

        $count = Db::name('shop_address')
            ->where('id', $id)
            ->where('user_id', $this->auth->id)
            ->delete();

        if ($count) {
            $this->success('删除成功');
        } else {
            $this->error('地址不存在或已删除');
        }
    }

    #[
        Apidoc\Title("设置默认地址"),
        Apidoc\Tag("商城,地址"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/shopAddress/setDefault"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Param(name: "id", type: "int", require: true, desc: "地址ID"),
    ]
    public function setDefault(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('参数错误');
        }

        Db::startTrans();
        try {
            $exist = Db::name('shop_address')
                ->where('id', $id)
                ->where('user_id', $this->auth->id)
                ->find();

            if (!$exist) {
                throw new \Exception('地址不存在');
            }

            // 取消其他默认地址
            Db::name('shop_address')
                ->where('user_id', $this->auth->id)
                ->update(['is_default' => '0']);

            // 设置当前地址为默认
            Db::name('shop_address')
                ->where('id', $id)
                ->update(['is_default' => '1']);

            Db::commit();
            $this->success('设置成功');
        } catch (HttpResponseException $e) {
            // success() 和 error() 抛出的 HttpResponseException，直接向上抛出，不做处理
            throw $e;
        } catch (\Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("获取默认地址"),
        Apidoc\Tag("商城,地址"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/shopAddress/getDefault"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
    ]
    public function getDefault(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $address = Db::name('shop_address')
            ->where('user_id', $this->auth->id)
            ->where('is_default', '1')
            ->find();

        if (!$address) {
            // 如果没有默认地址，返回第一个地址
            $address = Db::name('shop_address')
                ->where('user_id', $this->auth->id)
                ->order('id desc')
                ->find();
        }

        $this->success('', $address ?? []);
    }
}

