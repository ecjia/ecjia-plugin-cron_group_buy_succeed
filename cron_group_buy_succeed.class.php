<?php
//
//    ______         ______           __         __         ______
//   /\  ___\       /\  ___\         /\_\       /\_\       /\  __ \
//   \/\  __\       \/\ \____        \/\_\      \/\_\      \/\ \_\ \
//    \/\_____\      \/\_____\     /\_\/\_\      \/\_\      \/\_\ \_\
//     \/_____/       \/_____/     \/__\/_/       \/_/       \/_/ /_/
//
//   上海商创网络科技有限公司
//
//  ---------------------------------------------------------------------------------
//
//   一、协议的许可和权利
//
//    1. 您可以在完全遵守本协议的基础上，将本软件应用于商业用途；
//    2. 您可以在协议规定的约束和限制范围内修改本产品源代码或界面风格以适应您的要求；
//    3. 您拥有使用本产品中的全部内容资料、商品信息及其他信息的所有权，并独立承担与其内容相关的
//       法律义务；
//    4. 获得商业授权之后，您可以将本软件应用于商业用途，自授权时刻起，在技术支持期限内拥有通过
//       指定的方式获得指定范围内的技术支持服务；
//
//   二、协议的约束和限制
//
//    1. 未获商业授权之前，禁止将本软件用于商业用途（包括但不限于企业法人经营的产品、经营性产品
//       以及以盈利为目的或实现盈利产品）；
//    2. 未获商业授权之前，禁止在本产品的整体或在任何部分基础上发展任何派生版本、修改版本或第三
//       方版本用于重新开发；
//    3. 如果您未能遵守本协议的条款，您的授权将被终止，所被许可的权利将被收回并承担相应法律责任；
//
//   三、有限担保和免责声明
//
//    1. 本软件及所附带的文件是作为不提供任何明确的或隐含的赔偿或担保的形式提供的；
//    2. 用户出于自愿而使用本软件，您必须了解使用本软件的风险，在尚未获得商业授权之前，我们不承
//       诺提供任何形式的技术支持、使用担保，也不承担任何因使用本软件而产生问题的相关责任；
//    3. 上海商创网络科技有限公司不对使用本产品构建的商城中的内容信息承担责任，但在不侵犯用户隐
//       私信息的前提下，保留以任何方式获取用户信息及商品信息的权利；
//
//   有关本产品最终用户授权协议、商业授权与技术服务的详细内容，均由上海商创网络科技有限公司独家
//   提供。上海商创网络科技有限公司拥有在不事先通知的情况下，修改授权协议的权力，修改后的协议对
//   改变之日起的新授权用户生效。电子文本形式的授权协议如同双方书面签署的协议一样，具有完全的和
//   等同的法律效力。您一旦开始修改、安装或使用本产品，即被视为完全理解并接受本协议的各项条款，
//   在享有上述条款授予的权力的同时，受到相关的约束和限制。协议许可范围以外的行为，将直接违反本
//   授权协议并构成侵权，我们有权随时终止授权，责令停止损害，并保留追究相关责任的权力。
//
//  ---------------------------------------------------------------------------------
//
/**
 * 团购活动成功自动发送短信
 */
defined('IN_ECJIA') or exit('No permission resources.');

use Ecjia\App\Cron\CronAbstract;

class cron_group_buy_succeed extends CronAbstract
{

    /**
     * 计划任务执行方法
     */
    public function run()
    {
        $act_id_list = RC_DB::table('goods_activity')->where('store_id', '!=', 0)->where('is_finished', GBS_SUCCEED)->lists('act_id');

        if (!empty($act_id_list)) {
            RC_Loader::load_app_func('admin_order', 'orders');

            foreach ($act_id_list as $k => $v) {
                $group_buy = $this->group_buy_info($v['act_id']);

                if ($group_buy['total_order'] > 0) {
                    $order_id_list = RC_DB::table('order_info')
                        ->where('extension_code', 'group_buy')
                        ->where('extension_id', $v['act_id'])
                        ->whereIn('order_status', array(OS_CONFIRMED, OS_UNCONFIRMED))
                        ->lists('order_id');

                    $final_price = $group_buy['trans_price'];

                    $data = array(
                        'goods_price' => $final_price,
                    );
                    RC_DB::table('order_goods')->whereIn('order_id', $order_id_list)->update($data);

                    $res = RC_DB::table('order_goods')
                        ->select('order_id', RC_DB::raw('SUM(goods_number * goods_price) AS goods_amount'))
                        ->whereIn('order_id', $order_id_list)
                        ->groupBy('order_id')
                        ->get();

                    $orm_user_db = RC_Model::model('orders/orm_users_model');

                    if (!empty($res)) {
                        foreach ($res as $row) {
                            $order_id     = $row['order_id'];
                            $goods_amount = floatval($row['goods_amount']);

                            /* 取得订单信息 */
                            $order = RC_Api::api('orders', 'merchant_order_info', array('order_id' => $order_id, 'order_sn' => ''));

                            /* 判断订单是否有效：余额支付金额 + 已付款金额 >= 保证金 */
                            if ($order['surplus'] + $order['money_paid'] >= $group_buy['deposit']) {
                                $order['goods_amount'] = $goods_amount;
                                if ($order['insure_fee'] > 0) {
                                    $shipping            = ecjia_shipping::getPluginDataById($order['shipping_id']);
                                    $order['insure_fee'] = ecjia_shipping::insureFee($shipping['shipping_code'], $goods_amount, $shipping['insure']);
                                }
                                // 重算支付费用
                                $order['order_amount'] = $order['goods_amount'] + $order['shipping_fee'] + $order['tax']
                                    + $order['insure_fee'] + $order['pack_fee'] + $order['card_fee']
                                    - $order['money_paid'] - $order['surplus'];
                                $order['pay_fee']      = pay_fee($order['pay_id'], $order['order_amount']);


                                $order['order_amount'] += $order['pay_fee'];
                                if ($order['order_amount'] > 0) {
                                    $order['pay_status'] = PS_UNPAYED;
                                    $order['pay_time']   = 0;
                                } else {
                                    $order['pay_status'] = PS_PAYED;
                                    $order['pay_time']   = RC_Time::gmtime();
                                }

                                $order['order_status'] = OS_UNCONFIRMED;
                                $order['confirm_time'] = RC_Time::gmtime();
                                update_order($order_id, $order);
                            } else {
                                $order['order_status'] = OS_CANCELED;
                                $order['to_buyer']     = RC_Lang::get('groupbuy::groupbuy.cancel_order_reason');
                                $order['pay_status']   = PS_UNPAYED;
                                $order['pay_time']     = 0;
                                $money                 = $order['surplus'] + $order['money_paid'];
                                if ($money > 0) {
                                    $order['surplus']      = 0;
                                    $order['money_paid']   = 0;
                                    $order['order_amount'] = $money;
                                    order_refund($order, 1, RC_Lang::get('groupbuy::groupbuy.cancel_order_reason') . ':' . $order['order_sn']);
                                }
                                /* 更新订单 */
                                update_order($order['order_id'], $order);
                            }

                            $options  = array(
                                'mobile' => $order['mobile'],
                                'event'  => 'sms_groupbuy_activity_succeed',
                                'value'  => array(
                                    'user_name'  => $order['consignee'],
                                    'store_name' => $_SESSION['store_name'],
                                    'goods_name' => $order['goods_name']
                                )
                            );
                            $response = RC_Api::api('sms', 'send_event_sms', $options);

                            //消息通知
                            $user_name = RC_DB::table('users')->where('user_id', $order['user_id'])->pluck('user_name');
                            $user_ob   = $orm_user_db->find($order['user_id']);

                            $groupbuy_data      = array(
                                'title' => '团购活动成功结束',
                                'body'  => '您在' . $_SESSION['store_name'] . '店铺参加的商品' . $order['goods_name'] . '的团购活动现已结束， 请尽快支付订单剩余余款，方便及时给您发货。',
                                'data'  => array(
                                    'user_id'    => $order['user_id'],
                                    'user_name'  => $user_name,
                                    'store_name' => $_SESSION['store_name'],
                                    'goods_name' => $order['goods_name'],
                                    'order_id'   => $order['order_id'],
                                ),
                            );
                            $push_groupbuy_data = new GroupbuyActivitySucceed($groupbuy_data);
                            RC_Notification::send($user_ob, $push_groupbuy_data);
                        }
                    }
                }
            }
        }
    }

    /**
     * 获取插件代号
     *
     * @see \Ecjia\System\Plugin\PluginInterface::getCode()
     */
    public function getCode()
    {
        return $this->loadConfig('cron_code');
    }

    /**
     * 加载配置文件
     *
     * @see \Ecjia\System\Plugin\PluginInterface::loadConfig()
     */
    public function loadConfig($key = null, $default = null)
    {
        return $this->loadPluginData(RC_Plugin::plugin_dir_path(__FILE__) . 'config.php', $key, $default);
    }

    /**
     * 加载语言包
     *
     * @see \Ecjia\System\Plugin\PluginInterface::loadLanguage()
     */
    public function loadLanguage($key = null, $default = null)
    {
        $locale = RC_Config::get('system.locale');

        return $this->loadPluginData(RC_Plugin::plugin_dir_path(__FILE__) . '/languages/' . $locale . '/plugin.lang.php', $key, $default);
    }

    /**
     * 取得团购活动信息
     *
     * @param int $group_buy_id
     *            团购活动id
     * @param int $current_num
     *            本次购买数量（计算当前价时要加上的数量）
     * @return array status 状态：
     */
    public function group_buy_info($group_buy_id, $current_num = 0)
    {
        /* 取得团购活动信息 */
        $group_buy_id = intval($group_buy_id);
        $group_buy    = RC_DB::table('goods_activity')
            ->where('store_id', $_SESSION['store_id'])
            ->where('act_id', $group_buy_id)
            ->where('act_type', GAT_GROUP_BUY)
            ->select(RC_DB::raw('*, act_id as group_buy_id, act_desc as group_buy_desc, start_time as start_date, end_time as end_date'))
            ->first();

        /* 如果为空，返回空数组 */
        if (empty($group_buy)) {
            return array();
        }

        $ext_info  = unserialize($group_buy['ext_info']);
        $group_buy = array_merge($group_buy, $ext_info);

        /* 格式化时间 */
        $group_buy['formated_start_date'] = RC_Time::local_date('Y-m-d H:i:s', $group_buy['start_time']);
        $group_buy['formated_end_date']   = RC_Time::local_date('Y-m-d H:i:s', $group_buy['end_time']);

        /* 格式化保证金 */
        $group_buy['formated_deposit'] = price_format($group_buy['deposit'], false);

        /* 处理价格阶梯 */
        $price_ladder = $group_buy['price_ladder'];
        if (!is_array($price_ladder) || empty($price_ladder)) {
            $price_ladder = array(
                array('amount' => 0, 'price' => 0),
            );
        } else {
            foreach ($price_ladder as $key => $amount_price) {
                $price_ladder[$key]['formated_price'] = price_format($amount_price['price'], false);
            }
        }
        $group_buy['price_ladder'] = $price_ladder;

        /* 统计信息 */
        $stat      = $this->group_buy_stat($group_buy_id, $group_buy['deposit']);
        $group_buy = array_merge($group_buy, $stat);

        /* 计算当前价 */
        $cur_price  = $price_ladder[0]['price']; // 初始化
        $cur_amount = $stat['valid_goods'] + $current_num; // 当前数量
        foreach ($price_ladder as $amount_price) {
            if ($cur_amount >= $amount_price['amount']) {
                $cur_price = $amount_price['price'];
            } else {
                break;
            }
        }
        $group_buy['cur_price']          = $cur_price;
        $group_buy['formated_cur_price'] = price_format($cur_price, false);

        /* 最终价 */
        $group_buy['trans_price']          = $group_buy['cur_price'];
        $group_buy['formated_trans_price'] = $group_buy['formated_cur_price'];
        $group_buy['trans_amount']         = $group_buy['valid_goods'];

        /* 状态 */
        $group_buy['status'] = $this->group_buy_status($group_buy);

        if (RC_Lang::get('goods::goods.gbs.' . $group_buy['status'])) {
            $group_buy['status_desc'] = RC_Lang::get('goods::goods.gbs.' . $group_buy['status']);
        }

        $group_buy['start_time'] = $group_buy['formated_start_date'];
        $group_buy['end_time']   = $group_buy['formated_end_date'];

        return $group_buy;
    }

    /*
     * 取得某团购活动统计信息 @param int $group_buy_id 团购活动id
     * @param float $deposit 保证金
     *  @return array 统计信息
     *  total_order总订单数
     *  total_goods总商品数
     *  valid_order有效订单数
     *  valid_goods 有效商品数
     */
    public function group_buy_stat($group_buy_id, $deposit)
    {
        $group_buy_id = intval($group_buy_id);

        $group_buy_goods_id = RC_DB::table('goods_activity')->where('store_id', $_SESSION['store_id'])->where('act_id', $group_buy_id)->where('act_type', GAT_GROUP_BUY)->pluck('goods_id');

        /* 取得总订单数和总商品数 */
        $stat = RC_DB::table('order_info as o')->leftJoin('order_goods as g', RC_DB::raw('o.order_id'), '=', RC_DB::raw('g.order_id'))
            ->select(RC_DB::raw('COUNT(*) AS total_order'), RC_DB::raw('SUM(g.goods_number) AS total_goods'))
            ->where(RC_DB::raw('o.extension_code'), 'group_buy')
            ->where(RC_DB::raw('o.extension_id'), $group_buy_id)
            ->where(RC_DB::raw('g.goods_id'), $group_buy_goods_id)
            ->whereRaw("(order_status = '" . OS_CONFIRMED . "' OR order_status = '" . OS_UNCONFIRMED . "')")
            ->first();

        if ($stat['total_order'] == 0) {
            $stat['total_goods'] = 0;
        }

        /* 取得有效订单数和有效商品数 */
        $deposit = floatval($deposit);

        if ($deposit > 0 && $stat['total_order'] > 0) {
            $row = RC_DB::table('order_info as o')->leftJoin('order_goods as g', RC_DB::raw('o.order_id'), '=', RC_DB::raw('g.order_id'))
                ->select(RC_DB::raw('COUNT(*) AS total_order'), RC_DB::raw('SUM(g.goods_number) AS total_goods'))
                ->where(RC_DB::raw('o.extension_code'), 'group_buy')
                ->where(RC_DB::raw('o.extension_id'), $group_buy_id)
                ->where(RC_DB::raw('g.goods_id'), $group_buy_goods_id)
                ->whereRaw("(order_status = '" . OS_CONFIRMED . "' OR order_status = '" . OS_UNCONFIRMED . "')")
                ->whereRaw("(o.money_paid + o.surplus) >= '$deposit'")
                ->first();

            $stat['valid_order'] = $row['total_order'];
            if ($stat['valid_order'] == 0) {
                $stat['valid_goods'] = 0;
            } else {
                $stat['valid_goods'] = $row['total_goods'];
            }

        } else {
            $stat['valid_order'] = $stat['total_order'];
            $stat['valid_goods'] = $stat['total_goods'];
        }
        return $stat;
    }

    /**
     * 获得团购的状态
     *
     * @access public
     * @param
     *            array
     * @return integer
     */
    public function group_buy_status($group_buy)
    {
        $now = RC_Time::gmtime();
        if ($group_buy['is_finished'] == 0) {
            /* 未处理 */
            if ($now < $group_buy['start_time']) {
                $status = GBS_PRE_START;
            } elseif ($now > $group_buy['end_time']) {
                $status = GBS_FINISHED;
            } else {
                if ($group_buy['restrict_amount'] == 0 || $group_buy['valid_goods'] < $group_buy['restrict_amount']) {
                    $status = GBS_UNDER_WAY;
                } else {
                    $status = GBS_FINISHED;
                }
            }
        } elseif ($group_buy['is_finished'] == GBS_SUCCEED) {
            /* 已处理，团购成功 */
            $status = GBS_SUCCEED;
        } elseif ($group_buy['is_finished'] == GBS_FAIL) {
            /* 已处理，团购失败 */
            $status = GBS_FAIL;
        }
        return $status;
    }

}

// end