<?php

/**
 * Encapsulated manipulation of the current order using a singleton pattern.
 *
 * Ensures that an order is only started (persisted to DB) when necessary,
 * and all future changes are on the same order, until the order has is placed.
 * The requirement for starting an order is to adding an item to the cart.
 *
 * @package shop
 */
class ShoppingCart extends Object
{
    private static $cartid_session_name = 'shoppingcartid';

    private $order;

    private $calculateonce = false;

    private $message;

    private $type;

    /**
     * Access for only allowing access to one (singleton) ShoppingCart.
     *
     * @return ShoppingCart
     */
    public static function singleton()
    {
        return Injector::inst()->get('ShoppingCart');
    }

    /**
     * Shortened alias for ShoppingCart::singleton()->current()
     *
     * @return Order
     */
    public static function curr()
    {
        return self::singleton()->current();
    }

    /**
     * Get the current order, or return null if it doesn't exist.
     *
     * @return Order
     */
    public function current()
    {
        //find order by id saved to session (allows logging out and retaining cart contents)
        if (!$this->order && $sessionid = Session::get(self::config()->cartid_session_name)) {
            $this->order = Order::get()->filter(
                array(
                    "Status" => "Cart",
                    "ID" => $sessionid,
                )
            )->first();
        }
        if (!$this->calculateonce && $this->order) {
            $this->order->calculate();
            $this->calculateonce = true;
        }

        return $this->order ? $this->order : false;
    }

    /**
     * Set the current cart
     *
     * @param Order
     *
     * @return ShoppingCart
     */
    public function setCurrent(Order $cart)
    {
        if (!$cart->IsCart()) {
            trigger_error("Passed Order object is not cart status", E_ERROR);
        }
        $this->order = $cart;
        Session::set(self::config()->cartid_session_name, $cart->ID);

        return $this;
    }

    /**
     * Helper that only allows orders to be started internally.
     *
     * @return Order
     */
    protected function findOrMake()
    {
        if ($this->current()) {
            return $this->current();
        }
        $this->order = Order::create();
        if (Member::config()->login_joins_cart && Member::currentUserID()) {
            $this->order->MemberID = Member::currentUserID();
        }
        $this->order->write();
        $this->order->extend('onStartOrder');
        Session::set(self::config()->cartid_session_name, $this->order->ID);

        return $this->order;
    }

    /**
     * Adds an item to the cart
     *
     * @param Buyable $buyable
     * @param int $quantity
     * @param array $filter
     *
     * @return boolean|OrderItem false or the new/existing item
     */
    public function add(Buyable $buyable, $quantity = 1, $filter = array())
    {
        $order = $this->findOrMake();

        // If an extension throws an exception, error out
        try {
            $order->extend("beforeAdd", $buyable, $quantity, $filter);
        } catch (Exception $exception){
            return $this->error($exception->getMessage());
        }

        if (!$buyable) {
            return $this->error(_t("ShoppingCart.ProductNotFound", "Product not found."));
        }

        $item = $this->findOrMakeItem($buyable, $quantity, $filter);
        if (!$item) {
            return false;
        }
        if (!$item->_brandnew) {
            $item->Quantity += $quantity;
        } else {
            $item->Quantity = $quantity;
        }

        // If an extension throws an exception, error out
        try {
            $order->extend("afterAdd", $item, $buyable, $quantity, $filter);
        } catch (Exception $exception){
            return $this->error($exception->getMessage());
        }

        $item->write();
        $this->message(_t("ShoppingCart.ItemAdded", "Item has been added successfully."));

        return $item;
    }

    /**
     * Remove an item from the cart.
     *
     * @param Buyable $buyable
     * @param int $quantity - number of items to remove, or leave null for all items (default)
     * @param array $filter
     *
     * @return boolean success/failure
     */
    public function remove(Buyable $buyable, $quantity = null, $filter = array())
    {
        $order = $this->current();

        if (!$order) {
            return $this->error(_t("ShoppingCart.NoOrder", "No current order."));
        }

        // If an extension throws an exception, error out
        try {
            $order->extend("beforeRemove", $buyable, $quantity, $filter);
        } catch (Exception $exception){
            return $this->error($exception->getMessage());
        }

        $item = $this->get($buyable, $filter);

        if (!$item || !$this->removeOrderItem($item, $quantity)) {
            return false;
        }

        // If an extension throws an exception, error out
        // TODO: There should be a rollback
        try {
            $order->extend("afterRemove", $item, $buyable, $quantity, $filter);
        } catch (Exception $exception){
            return $this->error($exception->getMessage());
        }

        $this->message(_t("ShoppingCart.ItemRemoved", "Item has been successfully removed."));

        return true;
    }

    /**
     * Remove a specific order item from cart
     * @param OrderItem $item
     * @param int $quantity - number of items to remove or leave `null` to remove all items (default)
     * @return boolean success/failure
     */
    public function removeOrderItem(OrderItem $item, $quantity = null)
    {
        $order = $this->current();

        if (!$order) {
            return $this->error(_t("ShoppingCart.NoOrder", "No current order."));
        }

        if (!$item || $item->OrderID != $order->ID) {
            return $this->error(_t("ShoppingCart.ItemNotFound", "Item not found."));
        }

        //if $quantity will become 0, then remove all
        if (!$quantity || ($item->Quantity - $quantity) <= 0) {
            $item->delete();
            $item->destroy();
        } else {
            $item->Quantity -= $quantity;
            $item->write();
        }

        return true;
    }

    /**
     * Sets the quantity of an item in the cart.
     * Will automatically add or remove item, if necessary.
     *
     * @param Buyable $buyable
     * @param int $quantity
     * @param array $filter
     *
     * @return boolean|OrderItem false or the new/existing item
     */
    public function setQuantity(Buyable $buyable, $quantity = 1, $filter = array())
    {
        if ($quantity <= 0) {
            return $this->remove($buyable, $quantity, $filter);
        }
        $order = $this->findOrMake();
        $item = $this->findOrMakeItem($buyable, $quantity, $filter);

        if (!$item || !$this->updateOrderItemQuantity($item, $quantity, $filter)) {
            return false;
        }

        return $item;
    }

    /**
     * Update quantity of a given order item
     * @param OrderItem $item
     * @param int $quantity the new quantity to use
     * @param array $filter
     * @return boolean success/failure
     */
    public function updateOrderItemQuantity(OrderItem $item, $quantity = 1, $filter = array())
    {
        $order = $this->current();

        if (!$order) {
            return $this->error(_t("ShoppingCart.NoOrder", "No current order."));
        }

        if (!$item || $item->OrderID != $order->ID) {
            return $this->error(_t("ShoppingCart.ItemNotFound", "Item not found."));
        }

        $buyable = $item->Buyable();
        // If an extension throws an exception, error out
        try {
            $order->extend("beforeSetQuantity", $buyable, $quantity, $filter);
        } catch (Exception $exception){
            return $this->error($exception->getMessage());
        }

        $item->Quantity = $quantity;

        // If an extension throws an exception, error out
        try {
            $order->extend("afterSetQuantity", $item, $buyable, $quantity, $filter);
        } catch (Exception $exception){
            return $this->error($exception->getMessage());
        }

        $item->write();
        $this->message(_t("ShoppingCart.QuantitySet", "Quantity has been set."));

        return true;
    }

    /**
     * Finds or makes an order item for a given product + filter.
     *
     * @param Buyable $buyable the buyable
     * @param int $quantity quantity to add
     * @param array $filter
     *
     * @return OrderItem the found or created item
     */
    private function findOrMakeItem(Buyable $buyable, $quantity = 1, $filter = array())
    {
        $order = $this->findOrMake();

        if (!$buyable || !$order) {
            return false;
        }

        $item = $this->get($buyable, $filter);

        if (!$item) {
            $member = Member::currentUser();

            $buyable = $this->getCorrectBuyable($buyable);

            if (!$buyable->canPurchase($member, $quantity)) {
                return $this->error(
                    _t(
                        'ShoppingCart.CannotPurchase',
                        'This {Title} cannot be purchased.',
                        '',
                        array('Title' => $buyable->i18n_singular_name())
                    )
                );
                //TODO: produce a more specific message
            }

            $item = $buyable->createItem($quantity, $filter);
            $item->OrderID = $order->ID;
            $item->write();

            $order->Items()->add($item);

            $item->_brandnew = true; // flag as being new
        }

        return $item;
    }

    /**
     * Finds an existing order item.
     *
     * @param Buyable $buyable
     * @param array $customfilter
     *
     * @return OrderItem the item requested, or false
     */
    public function get(Buyable $buyable, $customfilter = array())
    {
        $order = $this->current();
        if (!$buyable || !$order) {
            return false;
        }

        $buyable = $this->getCorrectBuyable($buyable);

        $filter = array(
            'OrderID' => $order->ID,
        );
        $itemclass = Config::inst()->get(get_class($buyable), 'order_item');
        $relationship = Config::inst()->get($itemclass, 'buyable_relationship');
        $filter[$relationship . "ID"] = $buyable->ID;
        $required = array('Order', $relationship);
        if (is_array($itemclass::config()->required_fields)) {
            $required = array_merge($required, $itemclass::config()->required_fields);
        }
        $query = new MatchObjectFilter($itemclass, array_merge($customfilter, $filter), $required);
        $item = $itemclass::get()->where($query->getFilter())->first();
        if (!$item) {
            return $this->error(_t("ShoppingCart.ItemNotFound", "Item not found."));
        }

        return $item;
    }

    /**
     * Ensure the proper buyable will be returned for a given buyable…
     * This is being used to ensure a product with variations cannot be added to the cart…
     * a Variation has to be added instead!
     * @param Buyable $buyable
     * @return Buyable
     */
    public function getCorrectBuyable(Buyable $buyable)
    {
        if (
            $buyable instanceof Product &&
            $buyable->hasExtension('ProductVariationsExtension') &&
            $buyable->Variations()->count() > 0
        ) {
            foreach ($buyable->Variations() as $variation) {
                if ($variation->canPurchase()) {
                    return $variation;
                }
            }
        }

        return $buyable;
    }

    /**
     * Store old cart id in session order history
     * @param int|null $requestedOrderId optional parameter that denotes the order that was requested
     */
    public function archiveorderid($requestedOrderId = null)
    {
        $sessionId = Session::get(self::config()->cartid_session_name);
        $order = Order::get()
            ->filter("Status:not", "Cart")
            ->byId($sessionId);
        if ($order && !$order->IsCart()) {
            OrderManipulation::add_session_order($order);
        }
        // in case there was no order requested
        // OR there was an order requested AND it's the same one as currently in the session,
        // then clear the cart. This check is here to prevent clearing of the cart if the user just
        // wants to view an old order (via AccountPage).
        if (!$requestedOrderId || ($sessionId == $requestedOrderId)) {
            $this->clear();
        }
    }

    /**
     * Empty / abandon the entire cart.
     *
     * @param bool $write whether or not to write the abandoned order
     * @return bool - true if successful, false if no cart found
     */
    public function clear($write = true)
    {
        Session::clear(self::config()->cartid_session_name);
        $order = $this->current();
        $this->order = null;
        if (!$order) {
            return $this->error(_t("ShoppingCart.NoCartFound", "No cart found."));
        }
        if ($write) {
            $order->write();
        }
        $this->message(_t("ShoppingCart.Cleared", "Cart was successfully cleared."));

        return true;
    }

    /**
     * Store a new error.
     */
    protected function error($message)
    {
        $this->message($message, "bad");

        return false;
    }

    /**
     * Store a message to be fed back to user.
     *
     * @param string $message
     * @param string $type - good, bad, warning
     */
    protected function message($message, $type = "good")
    {
        $this->message = $message;
        $this->type = $type;
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function getMessageType()
    {
        return $this->type;
    }

    public function clearMessage()
    {
        $this->message = null;
    }

    //singleton protection
    public function __clone()
    {
        trigger_error('Clone is not allowed.', E_USER_ERROR);
    }

    public function __wakeup()
    {
        trigger_error('Unserializing is not allowed.', E_USER_ERROR);
    }
}

/**
 * Manipulate the cart via urls.
 */
class ShoppingCart_Controller extends Controller
{
    private static $url_segment         = "shoppingcart";

    private static $direct_to_cart_page = false;

    protected      $cart;

    private static $url_handlers        = array(
        '$Action/$Buyable/$ID' => 'handleAction',
    );

    private static $allowed_actions     = array(
        'add',
        'additem',
        'remove',
        'removeitem',
        'removeall',
        'removeallitem',
        'setquantity',
        'setquantityitem',
        'clear',
        'debug',
    );

    public static function add_item_link(Buyable $buyable, $parameters = array())
    {
        return self::build_url("add", $buyable, $parameters);
    }

    public static function remove_item_link(Buyable $buyable, $parameters = array())
    {
        return self::build_url("remove", $buyable, $parameters);
    }

    public static function remove_all_item_link(Buyable $buyable, $parameters = array())
    {
        return self::build_url("removeall", $buyable, $parameters);
    }

    public static function set_quantity_item_link(Buyable $buyable, $parameters = array())
    {
        return self::build_url("setquantity", $buyable, $parameters);
    }

    /**
     * Helper for creating a url
     */
    protected static function build_url($action, $buyable, $params = array())
    {
        if (!$action || !$buyable) {
            return false;
        }
        if (SecurityToken::is_enabled() && !self::config()->disable_security_token) {
            $params[SecurityToken::inst()->getName()] = SecurityToken::inst()->getValue();
        }
        return self::config()->url_segment . '/' .
        $action . '/' .
        $buyable->class . "/" .
        $buyable->ID .
        self::params_to_get_string($params);
    }

    /**
     * Creates the appropriate string parameters for links from array
     *
     * Produces string such as: MyParam%3D11%26OtherParam%3D1
     *     ...which decodes to: MyParam=11&OtherParam=1
     *
     * you will need to decode the url with javascript before using it.
     */
    protected static function params_to_get_string($array)
    {
        if ($array & count($array > 0)) {
            array_walk($array, create_function('&$v,$k', '$v = $k."=".$v ;'));
            return "?" . implode("&", $array);
        }
        return "";
    }

    /**
     * This is used here and in VariationForm and AddProductForm
     *
     * @param bool|string $status
     *
     * @return bool
     */
    public static function direct($status = true)
    {
        if (Director::is_ajax()) {
            return $status;
        }
        if (self::config()->direct_to_cart_page && $cartlink = CartPage::find_link()) {
            Controller::curr()->redirect($cartlink);
            return;
        } else {
            Controller::curr()->redirectBack();
            return;
        }
    }

    public function init()
    {
        parent::init();
        $this->cart = ShoppingCart::singleton();
    }

    /**
     * @return Product|ProductVariation|Buyable
     */
    protected function buyableFromRequest()
    {
        $request = $this->getRequest();
        if (
            SecurityToken::is_enabled() &&
            !self::config()->disable_security_token &&
            !SecurityToken::inst()->checkRequest($request)
        ) {
            return $this->httpError(
                400,
                _t("ShoppingCart.InvalidSecurityToken", "Invalid security token, possible CSRF attack.")
            );
        }
        $id = (int)$request->param('ID');
        if (empty($id)) {
            //TODO: store error message
            return null;
        }
        $buyableclass = "Product";
        if ($class = $request->param('Buyable')) {
            $buyableclass = Convert::raw2sql($class);
        }
        if (!ClassInfo::exists($buyableclass)) {
            //TODO: store error message
            return null;
        }
        //ensure only live products are returned, if they are versioned
        $buyable = Object::has_extension($buyableclass, 'Versioned')
            ?
            Versioned::get_by_stage($buyableclass, 'Live')->byID($id)
            :
            DataObject::get($buyableclass)->byID($id);
        if (!$buyable || !($buyable instanceof Buyable)) {
            //TODO: store error message
            return null;
        }

        return $this->cart->getCorrectBuyable($buyable);
    }

    /**
     * Action: add item to cart
     *
     * @param SS_HTTPRequest $request
     *
     * @return SS_HTTPResponse
     */
    public function add($request)
    {
        if ($product = $this->buyableFromRequest()) {
            $quantity = (int)$request->getVar('quantity');
            if (!$quantity) {
                $quantity = 1;
            }
            $this->cart->add($product, $quantity, $request->getVars());
        }

        $this->updateLocale($request);
        $this->extend('updateAddResponse', $request, $response, $product, $quantity);
        return $response ? $response : self::direct();
    }

    /**
     * Action: remove a certain number of items from the cart
     *
     * @param SS_HTTPRequest $request
     *
     * @return SS_HTTPResponse
     */
    public function remove($request)
    {
        if ($product = $this->buyableFromRequest()) {
            $this->cart->remove($product, $quantity = 1, $request->getVars());
        }

        $this->updateLocale($request);
        $this->extend('updateRemoveResponse', $request, $response, $product, $quantity);
        return $response ? $response : self::direct();
    }

    /**
     * Action: remove all of an item from the cart
     *
     * @param SS_HTTPRequest $request
     *
     * @return SS_HTTPResponse
     */
    public function removeall($request)
    {
        if ($product = $this->buyableFromRequest()) {
            $this->cart->remove($product, null, $request->getVars());
        }

        $this->updateLocale($request);
        $this->extend('updateRemoveAllResponse', $request, $response, $product);
        return $response ? $response : self::direct();
    }

    /**
     * Action: update the quantity of an item in the cart
     *
     * @param SS_HTTPRequest $request
     *
     * @return AjaxHTTPResponse|bool
     */
    public function setquantity($request)
    {
        $product = $this->buyableFromRequest();
        $quantity = (int)$request->getVar('quantity');
        if ($product) {
            $this->cart->setQuantity($product, $quantity, $request->getVars());
        }

        $this->updateLocale($request);
        $this->extend('updateSetQuantityResponse', $request, $response, $product, $quantity);
        return $response ? $response : self::direct();
    }

    /**
     * Action: clear the cart
     *
     * @param SS_HTTPRequest $request
     *
     * @return AjaxHTTPResponse|bool
     */
    public function clear($request)
    {
        $this->updateLocale($request);
        $this->cart->clear();
        $this->extend('updateClearResponse', $request, $response);
        return $response ? $response : self::direct();
    }

    /**
     * Handle index requests
     */
    public function index()
    {
        if ($cart = $this->Cart()) {
            $this->redirect($cart->CartLink);
            return;
        } elseif ($response = ErrorPage::response_for(404)) {
            return $response;
        }
        return $this->httpError(404, _t("ShoppingCart.NoCartInitialised", "no cart initialised"));
    }

    /**
     * Displays order info and cart contents.
     */
    public function debug()
    {
        if (Director::isDev() || Permission::check("ADMIN")) {
            //TODO: allow specifying a particular id to debug
            Requirements::css(SHOP_DIR . "/css/cartdebug.css");
            $order = ShoppingCart::curr();
            $content = ($order)
                ?
                Debug::text($order)
                :
                "Cart has not been created yet. Add a product.";
            return array('Content' => $content);
        }
    }

    protected function updateLocale($request)
    {
        $order = $this->cart->current();
        if ($request && $request->isAjax() && $order) {
            ShopTools::install_locale($order->Locale);
        }
    }
}
