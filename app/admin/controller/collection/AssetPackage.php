<?php

namespace app\admin\controller\collection;

use Throwable;
use app\common\controller\Backend;
use app\admin\model\AssetPackage as AssetPackageModel;
use think\facade\Db;

/**
 * 资产包管理控制器
 */
class AssetPackage extends Backend
{
    /**
     * @var AssetPackageModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['name', 'id'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new AssetPackageModel();
    }

    /**
     * 列表
     * @throws Throwable
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        [$where, $alias, $limit, $order] = $this->queryBuilder('id desc');

        $res = $this->model
            ->alias($alias)
            ->with(['session', 'zone'])
            ->where($where)
            ->order($order)
            ->paginate($limit);

        // 处理列表数据
        $list = $res->items();
        foreach ($list as &$item) {
            $item['session_name'] = $item['session']['title'] ?? '未关联';
            $item['zone_name'] = $item['zone_id'] == 0 ? '通用包' : ($item['zone']['name'] ?? '未知分区');
            
            // 🆕 查询实际商品数量
            $item['actual_item_count'] = Db::name('collection_item')
                ->where('package_id', $item['id'])
                ->count();
        }

        $this->success('', [
            'list' => $list,
            'total' => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 添加
     * @throws Throwable
     */
    public function add(): void
    {
        if ($this->request->isPost()) {
            $data = $this->excludeFields($this->request->post());
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            unset($data['create_time'], $data['update_time']);

            $result = false;
            $this->model->startTrans();
            try {
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate) {
                            $validate->scene('add');
                        }
                        $validate->check($data);
                    }
                }

                // 统一设置为通用包（zone_id = 0），因为每个资产包都会有多个价格分区的商品
                $data['zone_id'] = 0;
                
                // 允许多个默认包，系统会通过 order('is_default desc, total_count asc') 来优先选择默认包

                $result = $this->model->save($data);
                $this->model->commit();
            } catch (Throwable $e) {
                $this->model->rollback();
                $this->error($e->getMessage());
            }

            if ($result !== false) {
                // 🆕 自动生成藏品
                $itemCount = (int)($data['item_count'] ?? 0);
                if ($itemCount > 0) {
                    $generatedCount = $this->generateCollectionItems($this->model->id, $itemCount);
                    // 更新已生成数量
                    Db::name('asset_package')
                        ->where('id', $this->model->id)
                        ->update([
                            'generated_count' => $generatedCount,
                            'total_count' => $generatedCount,
                        ]);
                }
                $this->success(__('Added successfully'));
            }
            $this->error(__('No rows were added'));
        }

        // 获取场次和分区列表供选择
        $sessions = Db::name('collection_session')
            ->where('status', '1')
            ->field('id, title')
            ->order('id desc')
            ->select()
            ->toArray();

        $zones = Db::name('price_zone_config')
            ->where('status', '1')
            ->field('id, name, min_price, max_price')
            ->order('min_price asc')
            ->select()
            ->toArray();

        $this->success('', [
            'remark' => get_route_remark(),
            'sessions' => $sessions,
            'zones' => $zones,
        ]);
    }

    /**
     * 编辑
     * @throws Throwable
     */
    public function edit(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->param($pk);
        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if ($this->request->isPost()) {
            $data = $this->excludeFields($this->request->post());
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            unset($data['create_time'], $data['update_time']);

            $result = false;
            $this->model->startTrans();
            try {
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate) {
                            $validate->scene('edit');
                        }
                        $validate->check($data);
                    }
                }

                // 统一设置为通用包（zone_id = 0），因为每个资产包都会有多个价格分区的商品
                $data['zone_id'] = 0;
                
                // 允许多个默认包，系统会通过 order('is_default desc, total_count asc') 来优先选择默认包

                $result = $row->save($data);
                $this->model->commit();
            } catch (Throwable $e) {
                $this->model->rollback();
                $this->error($e->getMessage());
            }

            if ($result !== false) {
                // 🆕 如果 item_count 增加了，追加生成藏品
                $newItemCount = (int)($data['item_count'] ?? 0);
                
                // 🔧 修复：使用实际商品数量，而不是 generated_count
                $actualItemCount = (int)Db::name('collection_item')
                    ->where('package_id', $id)
                    ->count();
                
                // 需要新增的数量 = 新设定数量 - 实际商品数量
                $needGenerate = $newItemCount - $actualItemCount;
                
                if ($needGenerate > 0) {
                    $generatedCount = $this->generateCollectionItems($id, $needGenerate);
                    // 更新已生成数量和总数（同步为实际数量）
                    Db::name('asset_package')
                        ->where('id', $id)
                        ->update([
                            'generated_count' => $actualItemCount + $generatedCount,
                            'total_count' => $actualItemCount + $generatedCount,
                        ]);
                }
                
                $this->success(__('Updated successfully'));
            }
            $this->error(__('No rows were updated'));
        }

        // 获取场次和分区列表供选择
        $sessions = Db::name('collection_session')
            ->where('status', '1')
            ->field('id, title')
            ->order('id desc')
            ->select()
            ->toArray();

        $zones = Db::name('price_zone_config')
            ->where('status', '1')
            ->field('id, name, min_price, max_price')
            ->order('min_price asc')
            ->select()
            ->toArray();

        $this->success('', [
            'row' => $row,
            'remark' => get_route_remark(),
            'sessions' => $sessions,
            'zones' => $zones,
        ]);
    }

    /**
     * 删除
     * @throws Throwable
     */
    public function del(): void
    {
        $where = [];
        $dataLimitAdminIds = $this->getDataLimitAdminIds();
        if ($dataLimitAdminIds) {
            $where[] = [$this->dataLimitField, 'in', $dataLimitAdminIds];
        }

        $ids = $this->request->param('ids/a', []);
        $where[] = [$this->model->getPk(), 'in', $ids];
        $list = $this->model->where($where)->select();

        $count = 0;
        $this->model->startTrans();
        try {
            foreach ($list as $item) {
                // 获取关联的藏品ID列表
                $itemIds = Db::name('collection_item')
                    ->where('package_id', $item['id'])
                    ->column('id');

                // 级联删除关联数据
                if (!empty($itemIds)) {
                    // 删除寄售记录
                    Db::name('collection_consignment')
                        ->whereIn('item_id', $itemIds)
                        ->delete();

                    // 删除用户藏品记录
                    Db::name('user_collection')
                        ->whereIn('item_id', $itemIds)
                        ->delete();

                    // 删除藏品记录
                    Db::name('collection_item')
                        ->whereIn('id', $itemIds)
                        ->delete();
                }

                // 删除资产包相关的寄售记录（旧数据可能直接关联到资产包）
                Db::name('collection_consignment')
                    ->where('package_id', $item['id'])
                    ->delete();

                $count += $item->delete();
            }
            $this->model->commit();
        } catch (Throwable $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }

        if ($count) {
            $this->success(__('Deleted successfully'));
        }
        $this->error(__('No rows were deleted'));
    }

    /**
     * 设为默认
     */
    public function setDefault(): void
    {
        $id = $this->request->param('id/d', 0);
        if ($id <= 0) {
            $this->error('参数错误');
        }

        $row = $this->model->find($id);
        if (!$row) {
            $this->error('记录不存在');
        }

        Db::startTrans();
        try {
            // 设置当前为默认（允许多个默认包）
            $row->save(['is_default' => 1]);

            Db::commit();
            $this->success('设置成功');
        } catch (\Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    /**
     * 自动生成藏品
     * @param int $packageId 资产包ID
     * @param int $count 生成数量
     * @return int 成功生成的数量
     */
    private function generateCollectionItems(int $packageId, int $count): int
    {
        $package = Db::name('asset_package')->where('id', $packageId)->find();
        if (!$package) {
            return 0;
        }
        
        // 价格范围（最低350元）
        $minPrice = max(350, (float)($package['min_price'] ?? 350));
        $maxPrice = (float)($package['max_price'] ?? 0);
        if ($maxPrice <= 0 || $maxPrice < $minPrice) {
            $maxPrice = $minPrice + 150; // 默认范围150元
        }
        
        // 获取所有价格分区配置
        $zones = Db::name('price_zone_config')
            ->where('status', '1')
            ->order('min_price', 'asc')
            ->select()
            ->toArray();

        // 获取当前已生成的最大序号
        $maxSeq = Db::name('collection_item')
            ->where('package_id', $packageId)
            ->max('id') ?? 0;
        
        $items = [];
        $now = time();
        
        for ($i = 1; $i <= $count; $i++) {
            $seq = $maxSeq + $i;
            
            // 随机价格（分区范围内，最低350）
            $price = round($minPrice + (mt_rand() / mt_getrandmax()) * ($maxPrice - $minPrice), 2);
            
            // 根据价格匹配分区
            $matchZone = null;
            // 1. 优先匹配价格区间内的分区
            foreach ($zones as $zone) {
                if ($price >= $zone['min_price'] && $price <= $zone['max_price']) {
                    $matchZone = $zone;
                    break;
                }
            }
            // 2. 如果没有匹配到，找包含该价格的最小分区
            if (!$matchZone) {
                foreach ($zones as $zone) {
                    if ($price <= $zone['max_price']) {
                        $matchZone = $zone;
                        break;
                    }
                }
            }
            // 3. 兜底：使用最大的分区
            if (!$matchZone && !empty($zones)) {
                $matchZone = end($zones);
            }
            
            $zoneId = $matchZone ? $matchZone['id'] : 0;
            $priceZoneName = $matchZone ? mb_substr($matchZone['name'], 0, 10) : '普通区';

            // 生成确权编号：37-DATA-{包ID(4位)}-{序号(4位)}
            $assetCode = sprintf('37-DATA-%04d-%04d', $packageId, $seq);
            
            // 生成MD5指纹
            $fingerprint = '0x' . md5($assetCode . $now . $seq . mt_rand());
            
            $items[] = [
                'session_id' => $package['session_id'],
                'package_id' => $packageId,
                'package_name' => $package['name'],
                'title' => $package['name'],  // 藏品名称 = 资产包名称
                'image' => $package['cover_image'] ?? '',
                'price' => $price,
                'issue_price' => $price,
                'asset_anchor' => $package['asset_anchor'] ?? '', // 🆕 继承资产锚定
                'zone_id' => $zoneId,
                'price_zone' => $priceZoneName,
                'asset_code' => $assetCode,
                'tx_hash' => $fingerprint,
                'stock' => 1,
                'sales' => 0,
                'status' => '1',
                'is_physical' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ];
        }
        
        if (!empty($items)) {
            Db::name('collection_item')->insertAll($items);
            return count($items);
        }
        
        return 0;
    }
}
