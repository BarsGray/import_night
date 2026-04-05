
// ============================================================================================================================
// import_night
// ============================================================================================================================

function translit($text)
{
	$map = [
		'а' => 'a',
		'б' => 'b',
		'в' => 'v',
		'г' => 'g',
		'д' => 'd',
		'е' => 'e',
		'ё' => 'e',
		'ж' => 'zh',
		'з' => 'z',
		'и' => 'i',
		'й' => 'y',
		'к' => 'k',
		'л' => 'l',
		'м' => 'm',
		'н' => 'n',
		'о' => 'o',
		'п' => 'p',
		'р' => 'r',
		'с' => 's',
		'т' => 't',
		'у' => 'u',
		'ф' => 'f',
		'х' => 'h',
		'ц' => 'c',
		'ч' => 'ch',
		'ш' => 'sh',
		'щ' => 'shch',
		'ы' => 'y',
		'э' => 'e',
		'ю' => 'yu',
		'я' => 'ya'
	];

	$text = mb_strtolower($text);
	$text = strtr($text, $map);
	$text = preg_replace('/[^a-z0-9]+/', '-', $text);
	return trim($text, '-');
}






function creat_product()
{
	$catalog_file = __DIR__ . '/import__e42c06a9-7376-4dda-93f4-e54851e35e01.xml';
	$offers_file = __DIR__ . '/offers__8ee528fc-aee1-4912-9105-6ccf7f67d014.xml';

	$catalog = simplexml_load_file($catalog_file);
	$offers = simplexml_load_file($offers_file);



	foreach ($catalog->Каталог->Товары->Товар as $item) {
		$sku = (string) $item->Ид;
		$name = (string) $item->Наименование;
		$desc = (string) $item->Описание;

		$product_id = wc_get_product_id_by_sku($sku);


		if (!$product_id) {

			// проверка на вариативность
			// ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
			$has_variations = false;
			foreach ($offers->ПакетПредложений->Предложения->Предложение as $offer) {
				if (strpos((string) $offer->Ид, $sku . '#') === 0) {
					$has_variations = true;
					break;
				}
			}
			// ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++

			if ($has_variations) {
				$product = new WC_Product_Variable();
			} else {
				$product = new WC_Product_Simple();
				if (isset($item->Цена))
					$product->set_regular_price((float) $item->Цена);
			}

			$product->set_name($name);
			$product->set_sku($sku);
			$product->set_description($desc);
			$product->set_status('publish');
			$product_id = $product->save();

		}
	}
}







function creat_attrebutes()
{
	$offers_file = __DIR__ . '/offers__8ee528fc-aee1-4912-9105-6ccf7f67d014.xml';
	$offers = simplexml_load_file($offers_file);

	foreach ($offers->ПакетПредложений->Предложения->Предложение as $offer) {
		foreach ($offer->ХарактеристикиТовара->ХарактеристикаТовара as $attr) {

			// $name = ((string) $attr->Наименование);
			$name = trim((string) $attr->Наименование, "_ ");
			$value = ((string) $attr->Значение);

			$taxonomy = 'pa_' . translit($name);

			// создаём атрибут если нет
			if (!taxonomy_exists($taxonomy)) {
				$attribute_id = wc_create_attribute([
					'name' => $name,
					'slug' => translit($name),
					'type' => 'select',
					'order_by' => 'menu_order',
					'has_archives' => false,
				]);
				register_taxonomy($taxonomy, 'product', ['hierarchical' => true]);
			}

			// Создаём термин для атрибута
			if (!term_exists($value, $taxonomy)) {
				wp_insert_term($value, $taxonomy, ['slug' => translit($value)]);
			}
		}
	}
}





function add_attrebut_on_product()
{
	$offers_file = __DIR__ . '/offers__8ee528fc-aee1-4912-9105-6ccf7f67d014.xml';
	$offers = simplexml_load_file($offers_file);

	foreach ($offers->ПакетПредложений->Предложения->Предложение as $offer) {

		$full_id = (string) $offer->Ид;
		$parts = explode('#', $full_id);

		if (count($parts) < 2)
			continue;

		$product_sku = $parts[0];

		$product_id = wc_get_product_id_by_sku($product_sku);
		if (!$product_id)
			continue;

		$product = wc_get_product($product_id);


		foreach ($offer->ХарактеристикиТовара->ХарактеристикаТовара as $attr) {

			// $name = ((string) $attr->Наименование);
			$name = trim((string) $attr->Наименование, "_ ");
			$value = ((string) $attr->Значение);

			if (!$value)
				continue;

			$taxonomy = 'pa_' . translit($name);

			$term = get_term_by('name', $value, $taxonomy);

			$attrr = new WC_Product_Attribute();
			$attrr->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
			$attrr->set_name($taxonomy);
			$attrr->set_options([$term->name]);
			$attrr->set_visible(true);
			$attrr->set_variation(true);


			// Получаем текущие атрибуты
			$existing_attrs = $product->get_attributes();
			// Добавляем к существующим
			$existing_attrs[$taxonomy] = $attrr;

			$product->set_attributes($existing_attrs);
			$product->save();
		}
	}
}





function creat_variation()
{
	$offers_file = __DIR__ . '/offers__8ee528fc-aee1-4912-9105-6ccf7f67d014.xml';
	$offers = simplexml_load_file($offers_file);

	foreach ($offers->ПакетПредложений->Предложения->Предложение as $offer) {

		$full_id = (string) $offer->Ид;
		$parts = explode('#', $full_id);

		if (count($parts) < 2)
			continue;

		$product_sku = $parts[0];
		$variation_sku = $parts[1];

		$product_id = wc_get_product_id_by_sku($product_sku);
		if (!$product_id)
			continue;

		$variation_id = wc_get_product_id_by_sku($variation_sku);


		if (!$variation_id) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id($product_id);
			$variation->set_sku($variation_sku);
		} else {
			$variation = wc_get_product($variation_id);
		}


		$attributes = [];

		foreach ($offer->ХарактеристикиТовара->ХарактеристикаТовара as $attr) {

			// $name = ((string) $attr->Наименование);
			$name = trim((string) $attr->Наименование, "_ ");
			$value = ((string) $attr->Значение);

			if (!$value)
				continue;

			$taxonomy = 'pa_' . translit($name);

			$term = get_term_by('slug', translit($value), $taxonomy);

			// echo '<pre>';
			// print_r($term);
			// echo '</pre>';

			$attributes[$taxonomy] = $term->slug;
		}

		echo '<pre>';
		// print_r($attributes);
		// print_r($variation);
		echo '</pre>';

		$variation->set_attributes($attributes);
		$variation->save();
	}
}







function add_terms()
{

	$args = [
		'post_type' => 'product',
		'post_status' => 'publish',
		'posts_per_page' => -1,
		'fields' => 'ids',
	];

	$all_products = get_posts($args);

	foreach ($all_products as $product_id) {
		$product = wc_get_product($product_id);
		if (!$product)
			continue;

		$attributes = $product->get_attributes();

		foreach ($attributes as $taxonomy => $attr_obj) {
			if (!$attr_obj->is_taxonomy())
				continue;

			$terms = get_terms([
				'taxonomy' => $taxonomy,
				'hide_empty' => false,
			]);

			if (is_wp_error($terms) || empty($terms))
				continue;

			$all_slugs = wp_list_pluck($terms, 'name');

			$new_attr = new WC_Product_Attribute();
			$new_attr->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
			$new_attr->set_name($taxonomy);
			$new_attr->set_options($all_slugs);
			$new_attr->set_visible($attr_obj->get_visible());
			$new_attr->set_variation($attr_obj->get_variation());

			$attributes[$taxonomy] = $new_attr;
		}

		$product->set_attributes($attributes);
		$product->save();
	}
}






function add_price()
{
	$prices_file = __DIR__ . '/prices__ada214ad-6d44-4420-96a4-043b2219f0f9.xml';
	$prices = simplexml_load_file($prices_file);

	foreach ($prices->ПакетПредложений->Предложения->Предложение as $offer) {

		$full_id = (string) $offer->Ид;
		$parts = explode('#', $full_id);

		if (count($parts) < 2)
			continue;

		$variation_sku = $parts[1];

		$variation_id = wc_get_product_id_by_sku($variation_sku);

		if (!$variation_id)
			continue;

		$variation = wc_get_product($variation_id);

		$price = (float) $offer->Цены->Цена->ЦенаЗаЕдиницу;

		if ($price) {
			$variation->set_regular_price($price);
			$variation->set_price($price);
			$variation->save();
		}
	}
}







function add_image_to_product()
{
	require_once(ABSPATH . 'wp-admin/includes/media.php');
	require_once(ABSPATH . 'wp-admin/includes/file.php');
	require_once(ABSPATH . 'wp-admin/includes/image.php');

	$offers_file = __DIR__ . '/offers__8ee528fc-aee1-4912-9105-6ccf7f67d014.xml';
	$offers = simplexml_load_file($offers_file);

	foreach ($offers->ПакетПредложений->Предложения->Предложение as $offer) {

		$full_id = (string) $offer->Ид;
		$parts = explode('#', $full_id);

		if (count($parts) < 2)
			continue;

		$product_sku = $parts[0];

		$product_id = wc_get_product_id_by_sku($product_sku);
		if (!$product_id)
			continue;

		$gallery_ids = [];

		foreach ($offer->Картинка as $item_img) {

			if (!$item_img)
				continue;

			$image_url = get_template_directory_uri() . '/' . $item_img;

			$image_id = media_sideload_image($image_url, $product_id, null, 'id');

			if (!is_wp_error($image_id)) {
				$gallery_ids[] = $image_id;

				set_post_thumbnail($product_id, $image_id);
			}
		}

		if (!empty($gallery_ids)) {
			// первая картинка как миниатюра
			set_post_thumbnail($product_id, $gallery_ids[0]);

			// остальные картинки в галерею
			if (count($gallery_ids) > 1) {
				$gallery_ids_string = implode(',', array_slice($gallery_ids, 1));
				update_post_meta($product_id, '_product_image_gallery', $gallery_ids_string);
			}
		}
	}
}

add_action('init', function () {
	if (!isset($_GET['import_1c_full']) || $_GET['import_1c_full'] !== 'norm408')
		return;
	set_time_limit(0);

	// creat_product();
	// creat_attrebutes();
	// add_attrebut_on_product();
	// creat_variation();
	// add_terms();
	// add_price();
	// add_image_to_product();

// ============================================================================================================================
// import_night
// ============================================================================================================================
