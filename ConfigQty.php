
> app/etc/modules/Test_Configqty.xml

    <?xml version="1.0"?>
    <config>
      <modules>
        <Test_Configqty>
          <active>true</active>
          <codePool>local</codePool>
          <version>0.1.0</version>
        </Test_Configqty>
      </modules>
    </config>

> app/code/local/Test/Configqty/etc/config,xml

        <?xml version="1.0"?>
    <config>
      <modules>
        <Test_Configqty>
          <version>0.1.0</version>
        </Test_Configqty>
      </modules>
      <global>
        <helpers>
    		<configqty>
    			<class>Test_Configqty_Helper</class>
    		</configqty>
    		<catalogInventory>
    			<rewrite>
    				<data>Test_Configqty_Helper_Cataloginventorydata</data>
    			</rewrite>
    		</catalogInventory>
        </helpers>
    	<models>
    	  <configqty>
    		<class>Test_Configqty_Model</class>
    		<resourceModel>configqty_mysql4</resourceModel>
    	  </configqty>
    	</models>
        <events>
    		<catalog_product_prepare_save> 
    			<observers>
    			  <catalog_product_prepare_save_handler> 
    				<type>singleton</type> 
    				<class>configqty/observer</class> 
    				<method>configqty</method> 
    				<args></args> 
    			  </catalog_product_prepare_save_handler>
    			</observers>
    		</catalog_product_prepare_save>
    		
    		<cataloginventory_stock_item_save_commit_after>
                <observers>
                    <configqty_stockupdate>
                        <class>configqty/observer</class>
                        <method>catalogInventorySave</method>
                    </configqty_stockupdate>
                </observers>
            </cataloginventory_stock_item_save_commit_after>
    		
    		<sales_model_service_quote_submit_before>
                <observers>
                    <configqty_stockupdate>
                        <class>configqty/observer</class>
                        <method>subtractQuoteInventory</method>
                    </configqty_stockupdate>
                </observers>
            </sales_model_service_quote_submit_before>
    		
    		<sales_model_service_quote_submit_failure>
                <observers>
                    <configqty_stockupdate>
                        <class>configqty/observer</class>
                        <method>revertQuoteInventory</method>
                    </configqty_stockupdate>
                </observers>
            </sales_model_service_quote_submit_failure>
    		
    		<sales_order_creditmemo_save_after>
                <observers>
                    <configqty_stockupdate>
                        <class>configqty/observer</class>
                        <method>refundOrderInventory</method>
                    </configqty_stockupdate>
                </observers>
            </sales_order_creditmemo_save_after>
    		
        </events>
      </global>
    </config> 


> app/code/local/Test/Configqty/Helper/Data.php

    <?php
    class Test_Configqty_Helper_Data extends Mage_Core_Helper_Abstract
    {
    }
	 


> app/code/local/Test/Configqty/Helper/Cataloginventorydata.php

    <?php
    class Test_Configqty_Helper_Cataloginventorydata extends Mage_CatalogInventory_Helper_Data
    {
        public function getIsQtyTypeIds($filter = null)
        {
            if (null === self::$_isQtyTypeIds) {
                self::$_isQtyTypeIds = array();
                $productTypesXml = Mage::getConfig()->getNode('global/catalog/product/type');
                foreach ($productTypesXml->children() as $typeId => $configXml) {
                    self::$_isQtyTypeIds[$typeId] = (bool)$configXml->is_qty;
    				if($typeId == "configurable"){
    					self::$_isQtyTypeIds[$typeId] = true;
    				}
                }
            }
            if (null === $filter) {
                return self::$_isQtyTypeIds;
            }
            $result = self::$_isQtyTypeIds;
            foreach ($result as $key => $value) {
                if ($value !== $filter) {
                    unset($result[$key]);
                }
            }
            return $result;
        }
    }


> app/code/local/Test/Configqty/Model/Observer.php

    <?php
    class Test_Configqty_Model_Observer
    {
    	public function configqty(Varien_Event_Observer $observer)
    	{
    		$product = $observer->getProduct();
    		
    		$productId = $product->getId();
    		
    		if($product->getTypeID() == "configurable") {
    			$stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($productId);
    			
    			$p_qty = 0;
    			
    			$configurable_products_data = $product->getData('configurable_products_data');
    			
    			$childProducts = array_keys($configurable_products_data);
    			
    			foreach($childProducts as $child) {
    				$childStockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($child);
    				
    				$p_qty += $childStockItem->getQty();
    			}
    			
    			$stockItem->setQty($p_qty);
    			$stockItem->setIsInStock((int)($p_qty > 0));
    			
    			$product->setData('stock_item', $stockItem);
    		}
    	}
    	
    	
    	public function catalogInventorySave(Varien_Event_Observer $observer)
    	{	
    		$event = $observer->getEvent();
    		$_item = $event->getItem();
    
    		if ((int)$_item->getData('qty') != (int)$_item->getOrigData('qty')) {
    			
    			$product_id = $_item->getProductId();
    			$qty = $_item->getQty();
    			
    			$product = Mage::getModel('catalog/product')->load($product_id);
    			
    			if($product->getTypeId() == 'simple') {
    				$parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')
    					  ->getParentIdsByChild($product_id);
    				//var_dump($parentIds);
    				
    				foreach($parentIds as $parentId) {
    					//$parent = Mage::getModel('catalog/product')->load($parentId);
    					
    					$p_qty = 0;
    					$childProducts = Mage::getModel('catalog/product_type_configurable')
                        ->getChildrenIds($parentId);
    					
    					//var_dump($childProducts);
    					foreach($childProducts[0] as $child) {
    						$childStockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($child);
    						
    						$p_qty += $childStockItem->getQty();
    					}
    					
    					$parentStockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($parentId);
    					$parentStockItem->setQty($p_qty);
    					$parentStockItem->setIsInStock((int)($p_qty > 0));
    					$parentStockItem->save();
    				}
    			}
    			//exit;
    			/* $params['qty'] = 100;
    			$params['qty_change'] = $_item->getQty() - $_item->getOrigData('qty'); */
    			
    			/* $qty = 100;
    			$_item->setQty($qty);
    			$_item->setIsInStock((int)($qty > 0));
    			$_item->save(); */
    		}
    		
    	}
    	public function subtractQuoteInventory(Varien_Event_Observer $observer)
    	{
    		$quote = $observer->getEvent()->getQuote();
    		foreach ($quote->getAllItems() as $item) {
    			
    			$product_id = $item->getProductId();
    			$qty = $item->getQty();
    			
    			$product = Mage::getModel('catalog/product')->load($product_id);
    			
    			
    			if($product->getTypeId() == 'simple') {
    				$parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')
    					  ->getParentIdsByChild($product_id);
    				//var_dump($parentIds);
    				
    				foreach($parentIds as $parentId) {
    					//$parent = Mage::getModel('catalog/product')->load($parentId);
    					
    					$p_qty = 0;
    					$childProducts = Mage::getModel('catalog/product_type_configurable')
                        ->getChildrenIds($parentId);
    					
    					//var_dump($childProducts);
    					foreach($childProducts[0] as $child) {
    						$childStockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($child);
    						
    						$p_qty += $childStockItem->getQty();
    					}
    					
    					$parentStockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($parentId);
    					$parentStockItem->setQty($p_qty);
    					$parentStockItem->setIsInStock((int)($p_qty > 0));
    					$parentStockItem->save();
    					
    					//Mage::log("p_qty: ".$p_qty. "  product_id".$product_id, null, 'mylogfile.log');
    				}
    			}
    		}
    	}
    	public function revertQuoteInventory(Varien_Event_Observer $observer)
    	{
    		$quote = $observer->getEvent()->getQuote();
    		foreach ($quote->getAllItems() as $item) {
    			
    			$product_id = $item->getProductId();
    			$qty = $item->getQty();
    			
    			$product = Mage::getModel('catalog/product')->load($product_id);
    			
    			
    			if($product->getTypeId() == 'simple') {
    				$parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')
    					  ->getParentIdsByChild($product_id);
    				//var_dump($parentIds);
    				
    				foreach($parentIds as $parentId) {
    					//$parent = Mage::getModel('catalog/product')->load($parentId);
    					
    					$p_qty = 0;
    					$childProducts = Mage::getModel('catalog/product_type_configurable')
                        ->getChildrenIds($parentId);
    					
    					//var_dump($childProducts);
    					foreach($childProducts[0] as $child) {
    						$childStockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($child);
    						
    						$p_qty += $childStockItem->getQty();
    					}
    					
    					$parentStockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($parentId);
    					$parentStockItem->setQty($p_qty);
    					$parentStockItem->setIsInStock((int)($p_qty > 0));
    					$parentStockItem->save();
    					
    					//Mage::log("p_qty: ".$p_qty. "  product_id".$product_id, null, 'mylogfile.log');
    				}
    			}
    		}
    	}
    	public function refundOrderInventory(Varien_Event_Observer $observer)
    	{
    		$creditmemo = $observer->getEvent()->getCreditmemo();
    		foreach ($creditmemo->getAllItems() as $item) {
    			
    			$product_id = $item->getProductId();
    			$qty = $item->getQty();
    			
    			$product = Mage::getModel('catalog/product')->load($product_id);
    			
    			
    			if($product->getTypeId() == 'simple') {
    				$parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')
    					  ->getParentIdsByChild($product_id);
    				//var_dump($parentIds);
    				
    				foreach($parentIds as $parentId) {
    					//$parent = Mage::getModel('catalog/product')->load($parentId);
    					
    					$p_qty = 0;
    					$childProducts = Mage::getModel('catalog/product_type_configurable')
                        ->getChildrenIds($parentId);
    					
    					//var_dump($childProducts);
    					foreach($childProducts[0] as $child) {
    						$childStockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($child);
    						
    						$p_qty += $childStockItem->getQty();
    					}
    					
    					$parentStockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($parentId);
    					$parentStockItem->setQty($p_qty);
    					$parentStockItem->setIsInStock((int)($p_qty > 0));
    					$parentStockItem->save();
    					
    					//Mage::log("p_qty: ".$p_qty. "  product_id".$product_id, null, 'mylogfile.log');
    				}
    			}
    	    }
    	}
    }

