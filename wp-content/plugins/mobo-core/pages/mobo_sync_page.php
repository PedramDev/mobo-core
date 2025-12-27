<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
// https://mobomobo.ir/admin/store/products/159500255
$isSyncActive = 0;

function mobo_core_sync_categories()
{
    trace_log();
    trace_log();

    try {

        $global_update_categories = get_option('global_update_categories');

        if ($global_update_categories == '1') {
            $apiFunc = new \MoboCore\ApiFunctions();
            $categoriesDataJson = $apiFunc->getCategoriesAsJson();
            $catFunc = new \MoboCore\WooCommerceCategoryManager();
            $catFunc->addOrUpdateAllCategories($categoriesDataJson);
            trace_log();
        }
        trace_log();
    } finally {
        trace_log();
    }
}

$is24 = false;
function mobo_core_sync_products_24()
{
    global $is24;

    $is24 = true;
    update_option('mobo_sync_page', 1);
    mobo_core_sync_products();
}

function mobo_core_sync_products()
{
    trace_log();

    try {
        trace_log();

        $apiFunc = new \MoboCore\ApiFunctions(); // Replace with your API function class
        $productFunc = new \MoboCore\WooCommerceProductManager(); // Replace with your product function class

        // Retrieve stored values
        $page = intval(get_option('mobo_sync_page', 1));
        $mobo_core_page_size = intval(get_option('mobo_core_page_size', 5));
        $onlyInStock = intval(get_option('mobo_core_only_in_stock', true));
        trace_log();


        $totalCount =  $apiFunc->getProductsCount($onlyInStock);
        if ($totalCount === false) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p>خطا در دریافت تعداد محصولات</p></div>';
            });
            return;
        }
        $productLeft = 0;

        $totalCount = intval($totalCount);

        trace_log('$totalCount:' . $totalCount);
        trace_log('$mobo_core_page_size:' . $mobo_core_page_size);
        trace_log('$page:' . $page);
        trace_log('calculated:' . intval($totalCount - ($page * $mobo_core_page_size)));
        if ($page > 0 && $mobo_core_page_size > 0) {
            $productLeft = intval($totalCount - ($page * $mobo_core_page_size));
            // if($prioductLeft <= $totalCount)
        } else {
            $productLeft = 0; // Or handle as appropriate
        }
        trace_log();
        trace_log('$productLeft:' . $productLeft);
        trace_log('$totalCount:' . $totalCount);
        trace_log();

        // Process products
        if ($productLeft > 0) {
            trace_log();
            $productsDataJson = $apiFunc->getProductsAsJson($page, $mobo_core_page_size, $onlyInStock);
            if ($productsDataJson === false) {
                add_action('admin_notices', function () {
                    echo '<div class="error"><p>خطا در دریافت محصولات</p></div>';
                });
                return;
            }
            trace_log(print_r($productsDataJson));
            trace_log();

            $productFunc->update_product($productsDataJson);
            trace_log();

            // Update counters
            $productLeft -= $mobo_core_page_size;
            $page++;

            // Update options
            update_option('mobo_sync_page', $page);
            trace_log();
        } else {
            $page = 1;
            $productLeft = 0;
            trace_log();
            mobo_core_sync_stop();
        }

        // Cleanup after completion
    } finally {
        if ($productLeft <= 0) {
            mobo_core_sync_stop();
        }
    }
}


add_action('mobo_core_sync_products_24_event', 'mobo_core_sync_products_24');
add_action('mobo_core_sync_products_event', 'mobo_core_sync_products');
add_action('mobo_core_sync_categories_event', 'mobo_core_sync_categories');


function mobo_core_sync_stop()
{
    global $isSyncActive;

    trace_log();
    delete_option('mobo_sync_page');
    delete_option('mobo_sync_product_left');
    trace_log();

    $timestamp1 = wp_next_scheduled('mobo_core_sync_products_event'); // Get the next scheduled time
    if ($timestamp1) {
        trace_log();
        wp_unschedule_event($timestamp1, 'mobo_core_sync_products_event'); // Remove the event
    }

    $timestamp2 = wp_next_scheduled('mobo_core_sync_categories_event'); // Get the next scheduled time
    if ($timestamp2) {
        trace_log();
        wp_unschedule_event($timestamp2, 'mobo_core_sync_categories_event'); // Remove the event
    }

    trace_log();

    add_action('admin_notices', function () {
        echo '<div class="updated"><p>همگام سازی متوقف شد!</p></div>';
    });
    trace_log();

    $isSyncActive = 0;
    update_option('mobo_manual_sync', 0);


    trace_log();
}


// Admin page function
function mobo_core_sync_page()
{
    trace_log();

    $apiFunc = new \MoboCore\ApiFunctions(); // Replace with your API function class
    $info = $apiFunc->getLicenseInfo();

    if (!mobo_isLicenseExpired()) {
        global $isSyncActive;
        
        $isSyncActive = intval(get_option('mobo_manual_sync', 0));

        if (!$isSyncActive) {
            $page = 1;
        }

        $page = intval(get_option('mobo_sync_page', 1));
        $onlyInStock = intval(get_option('mobo_core_only_in_stock', true));

        $totalCount =  $apiFunc->getProductsCount($onlyInStock);
            if ($totalCount === false) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p>خطا در دریافت تعداد محصولات</p></div>';
            });
            return;
        }
        $totalCount = intval($totalCount);

        $mobo_core_page_size = intval(get_option('mobo_core_page_size', 5));
        if (isset($_POST['submit'])) {
            if (isset($_POST['mobo_core_sync_categories'])) {
                // Verify nonce for security
                check_admin_referer('mobo_core_sync_categories_nounce');

                trace_log();
                trace_log('$isSyncActive:' . $isSyncActive);
                if ($isSyncActive) {
                    trace_log();
                    $isSyncActive = 0;
                } else {
                    trace_log();
                    $isSyncActive = 1;
                }
                update_option('mobo_manual_sync', $isSyncActive);

                // Optional: Add an admin notice
                add_action('admin_notices', function () {
                    echo '<div class="updated"><p>همگام سازی دسته بندی موبو کور با موفقیت انجام شد!</p></div>';
                });

                $apiFunc = new \MoboCore\ApiFunctions();

                $global_update_categories = get_option('global_update_categories');
                if ($global_update_categories == '1') {
                    $categoriesDataJson = $apiFunc->getCategoriesAsJson();
                    $catFunc = new \MoboCore\WooCommerceCategoryManager();
                    $catFunc->addOrUpdateAllCategories($categoriesDataJson);
                }

                if ($isSyncActive) {
                    wp_schedule_event(time(), 'mobo_core_categories_interval', 'mobo_core_sync_categories_event');
                    wp_schedule_event(time(), 'mobo_core_product_interval', 'mobo_core_sync_products_event');
                }
            } else if (isset($_POST['mobo_core_update_setting'])) {
                check_admin_referer('mobo_core_update_setting_nounce');

                $page = intval($_POST['page']);
                update_option('mobo_sync_page', $page);
                
                update_option('mobo_core_page_size', intval(trim($_POST['mobo_core_page_size'])));
                $mobo_core_page_size = $_POST['mobo_core_page_size'];

                if (get_option('mobo_core_page_size') != trim($_POST['mobo_core_page_size'])) {
                    //stop sync
                    trace_log('mobo_core_page_size:' . get_option('mobo_core_page_size') . ':' . trim($_POST['mobo_core_page_size']));
                    mobo_core_sync_stop();
                } else {
                    $page = intval($_POST['page']);
                    if ($page > 0 && $mobo_core_page_size > 0) {
                        $productLeft = intval($totalCount - ($page * $mobo_core_page_size));
                    } else {
                        $productLeft = 0; // Or handle as appropriate
                    }
                }
            } else if (isset($_POST['mobo_core_sync_stop'])) {
                check_admin_referer('mobo_core_sync_stop_nounce');
                mobo_core_sync_stop();
            }

            if ($page > 0 && $mobo_core_page_size > 0) {
                $productLeft = intval($totalCount - ($page * $mobo_core_page_size));
            } else {
                $productLeft = 0; // Or handle as appropriate
            }
        }
        else{
            if ($page > 0 && $mobo_core_page_size > 0) {
                if(!$isSyncActive){
                    $productLeft = $totalCount;
                }
                else{
                    $productLeft = intval($totalCount - ($page * $mobo_core_page_size));
                }
            } else {
                $productLeft = 0; // Or handle as appropriate
            }
        }
?>
        <p>
            <?php echo $info['message']; ?>
            <br />
            <?php echo $info['timeLeft']; ?>
        </p>
        <hr />
        <div>
            وضعیت همگام سازی :
            <br>
            <?php
            trace_log();
            trace_log('$isSyncActive:' . $isSyncActive);
            if (!$isSyncActive) {
                echo '<span style="color:red">حالت دستی : غیر فعال</span>';
            } else {
                echo '<span style="color:green">حالت دستی : فعال</span>';
            }
            ?>
            <hr />
            <?php
            global $is24;
            if (!$is24) {
                echo '<span style="color:red">حالت زمانبندی شده محصول : غیر فعال</span>';
            } else {
                echo '<span style="color:green">حالت زمانبندی شده محصول : فعال</span>';
            }
            ?>
        </div>
        <hr />

        <p>
            <?php
            $minutes = 0;
            $seconds = 0;

            if ($productLeft > 0) {
                $intervals = $productLeft / intval($mobo_core_page_size);
                $totalTimeInSeconds = $intervals * 40;
                $minutes = round(floor($totalTimeInSeconds / 60));
                $seconds = round($totalTimeInSeconds % 60);
            }

            ?>
            تعداد محصول همگام سازی نشده : <?php echo $productLeft; ?>
            <br />
            زمان تقریبی پایان همگام سازی : <?php echo $minutes; ?> دقیقه و <?php echo $seconds; ?> ثانیه
        </p>

        <hr />

        <form method="post" action="">
            <p>
                در صورتی که نیاز دارید از اول همگام سازی صورت بگیرد شماره درخواست را به 1 تغییر دهید
                در غیر این صورت بهتر است به آن کاری نداشته باشید
            </p>
            <input type="hidden" name="mobo_core_update_setting" value="mobo_core_update_setting" />
            <?php wp_nonce_field('mobo_core_update_setting_nounce'); ?>

            <label for="page">شماره درخواست :</label>
            <input type="number" name="page" id="page" step="1" value="<?php echo $page; ?>" />

            <hr />

            <label for="mobo_core_page_size">
                تعداد دریافت محصول در هر درخواست
            </label>
            <input type="number" style="font-family:'Courier New', Courier, monospace;" dir="ltr" name="mobo_core_page_size" id="mobo_core_page_size" value="<?php echo $mobo_core_page_size; ?>" />


            <?php submit_button('بروزرسانی تنظیمات'); ?>
        </form>

        <hr />

        <?php if (!$isSyncActive) { ?>
            <form method="post" action="">
                <input type="hidden" name="mobo_core_sync_categories" value="mobo_core_sync_categories" />
                <?php wp_nonce_field('mobo_core_sync_categories_nounce'); ?>


                <?php submit_button('همگام سازی'); ?>
            </form>
        <?php } ?>

        <?php if ($isSyncActive) { ?>
            <form method="post" action="">
                <input type="hidden" name="mobo_core_sync_stop" value="mobo_core_sync_stop" />
                <?php wp_nonce_field('mobo_core_sync_stop_nounce'); ?>


                <?php submit_button('توقف همگام سازی'); ?>
            </form>
        <?php } ?>
    <?php
    } else {
    ?>
        <p>
            <?php echo $info['message']; ?>
            <br />
            <?php echo $info['timeLeft']; ?>
        </p>
<?php
    }
}
