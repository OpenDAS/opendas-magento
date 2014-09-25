<?php
/**
 * @category   OpenDAS
 * @package    Opendas_Shipping
 * @author     www.asperience.fr
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Opendas_Shipping_Model_Order_Api extends Mage_Sales_Model_Api_Resource
{

    protected function _initOrder($orderId)
    {
        $order = Mage::getModel('sales/order');

        /* @var $order Mage_Sales_Model_Order */

        $order->load($orderId);

        if (!$order->getId()) {
            $this->_fault('not_exists');
        }

        return $order;
    }
    
	protected function _getTalendStruct($result = array(),$message='ok',$code=0)
	{
		return array('code'=> $code, 'string'=> $message, 'object'=> $result);
	}
	
	protected function _getIdInTalendFilters($filters)
	{
		$id = false;
		Mage::log("-------------_getIdInTalendFilters-------");
		Mage::log($filters);
		Mage::log("--------------/_getIdInTalendFilters------");
		if (is_array($filters)) {
			Mage::log("1");
			Mage::log($filters);
			try {
                foreach ($filters as $value) {
                	Mage::log("2");
                	if (count($value) > 2) {
                		Mage::log("3");
						if ($value[0] == 'id') {
							Mage::log("4");
							if (is_array($value[2])) {
								Mage::log("5");
								$id = $value[2][0];
							} else {
								Mage::log("6");
								$id = $value[2];
							}
							break;
						}
                	}
                }
            } catch (Mage_Core_Exception $e) {
            	Mage::log("except");
                $this->_fault('filters_invalid', $e->getMessage());
            } 
		} else {
        	$id = $filters;
        }
		return $id;
	}
	
    public function ordersCanShip($context = null, $filters = null)
    {
        
    	Mage::log('Opendas_Shipping_Model_Order_Api 1 -> ordersCanShip');
    	Mage::log($context);
    	Mage::log($filters);
        $collection = Mage::getResourceModel('sales/order_collection')
            ->addAttributeToSelect('entity_id')
        	->addAttributeToSelect('increment_id')
            ->addAttributeToFilter('status', array('pending','processing'));

		$collection->getSelect()->join(
                array('items'=>$collection->getTable('sales/order_item')),
                'items.order_id=main_table.entity_id',
                array('qty_to_ship'=>new Zend_Db_Expr('SUM(items.qty_ordered-items.qty_canceled-items.qty_shipped)', 'parent_item'))
            )
            ->where('items.parent_item_id is NULL and items.is_virtual <> 1')
            ->group('main_table.entity_id')
            ->having(new Zend_Db_Expr('SUM(items.qty_ordered-items.qty_shipped-items.qty_refunded-items.qty_canceled)', array('gt' => 0)));
		$collection->load(false, true);
        
        $result = array();
	
        foreach ($collection as $order) {
        	Mage::log($order->getData());
            $result[] = array('id' => $order->getId(), 'name' => $order->getIncrementId());
            
        }
        
		Mage::log(count($result));
        return $this->_getTalendStruct($result);
    }
    
	public function itemsCanShip($context = null, $filters = null)
    {
    	Mage::log('Opendas_Shipping_Model_Order_Api 2 -> itemsCanShip');
    	Mage::log($context);
    	Mage::log("---------/filter");
    	Mage::log($filters);
    	Mage::log("---------/filter");
    	Mage::log($this->_getIdInTalendFilters($filters));
        $order = $this->_initOrder($this->_getIdInTalendFilters($filters));
		Mage::log($order->getId());
        foreach ($order->getAllItems() as $item) {
			$qty = intval($item->getQtyToShip());
			if ($item->canShip()) {
	            $result[] = array(
	            				'id' => $item->getId(), 
	            				'name' => $item->getName(),
	            				'qty' => $qty,
	            );
			}
        }
        Mage::log($result);

        return $this->_getTalendStruct($result);
    }
    
	public function createShipment($context = null, $filters = null)
    {
        Mage::log('Opendas_Shipping_Model_Order_Api 3 -> createShip');
    	Mage::log($context);
    	Mage::log($filters);

    	$order = $this->_initOrder($this->_getIdInTalendFilters($filters));

        /**
          * Check order existing
          */
        if (!$order->getId()) {
             $this->_fault('order_not_exists');
        }

        /**
         * Check shipment create availability
         */
        if (!$order->canShip()) {
             $this->_fault('data_invalid', Mage::helper('sales')->__('Can not do shipment for order.'));
		return $this->_getTalendStruct(array(),Mage::helper('sales')->__('Can not do shipment for order.'),1);
        }

        $convertor   = Mage::getModel('sales/convert_order');
        $shipment    = $convertor->toShipment($order);
         /* @var $shipment Mage_Sales_Model_Order_Shipment */
        
        $itemsQty = array();
        
		if (isset($context['item_ids'])) {
        	foreach ($context['item_ids'] as $item) {
        		if (isset($itemsQty[$item])) {
        			$itemsQty[$item] += 1;
				} else {
        			$itemsQty[$item] = 1;
				}
			}
		}
        foreach ($order->getAllItems() as $orderItem) {
            if (!$orderItem->getQtyToShip()) {
                continue;
            }
            if ($orderItem->getIsVirtual()) {
                continue;
            }
            if ((!isset($itemsQty[$orderItem->getId()])) && (count($itemsQty))) {
                continue;
            }
            
            $item = $convertor->itemToShipmentItem($orderItem);
            if (isset($itemsQty[$orderItem->getId()])) {
                $qty = $itemsQty[$orderItem->getId()];
            }
            else {
                $qty = $orderItem->getQtyToShip();
            }
            $item->setQty($qty);
        	$shipment->addItem($item);
        }
        $shipment->register();
//        $shipment->addComment($comment, $email && $includeComment);

        if ($order->getCustomerEmail()) {
            $shipment->setEmailSent(true);
        }

        $shipment->getOrder()->setIsInProcess(true);

        try {
            $transactionSave = Mage::getModel('core/resource_transaction')
                ->addObject($shipment)
                ->addObject($shipment->getOrder())
                ->save();

            //$shipment->sendEmail($email, '');
        } catch (Mage_Core_Exception $e) {
			Mage::log($e->getMessage());
			return $this->_getTalendStruct(array(),$e->getMessage(),1);
			$this->_fault('data_invalid', $e->getMessage());
        }
		
        return $this->_getTalendStruct();
    }

}
