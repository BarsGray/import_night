

// Создаём родителя
$product = new WC_Product_Variable();
$product->set_name('Футболка Пример');
$product->set_sku('shirt001');
$product->set_status('publish');
$product_id = $product->save();

// Создаём глобальный атрибут цвета, если его нет
$taxonomy = 'pa_color';
if (!taxonomy_exists($taxonomy)) {
	$attribute_id = wc_create_attribute([
		'name' => 'Color',
		'slug' => 'color',
		'type' => 'select',
		'order_by' => 'menu_order',
		'has_archives' => false,
	]);
	register_taxonomy($taxonomy, 'product', ['hierarchical' => true]);
}

// Создаём термины для атрибута
if (!term_exists('Red', $taxonomy))
	wp_insert_term('Red', $taxonomy, ['slug' => 'red']);
if (!term_exists('Blue', $taxonomy))
	wp_insert_term('Blue', $taxonomy, ['slug' => 'blue']);

// Привязываем атрибут к родительскому продукту через WC_Product_Attribute
$attr = new WC_Product_Attribute();
$attr->set_id(wc_attribute_taxonomy_id_by_name('color')); // ID глобального атрибута
$attr->set_name($taxonomy);
$attr->set_options(['red', 'blue']); // Слаг термина!
$attr->set_position(0);
$attr->set_visible(true);
$attr->set_variation(true);

$product->set_attributes([$attr]);
$product->save();

// Создаём вариацию Red
$variation = new WC_Product_Variation();
$variation->set_parent_id($product_id);
$variation->set_sku('shirt001-red');
$variation->set_regular_price(1000);
$variation->set_attributes([
	$taxonomy => 'red', // slug
]);
$variation->save();

// Создаём вариацию Blue
$variation2 = new WC_Product_Variation();
$variation2->set_parent_id($product_id);
$variation2->set_sku('shirt001-blue');
$variation2->set_regular_price(1100);
$variation2->set_attributes([
	$taxonomy => 'blue',
]);
$variation2->save();

echo "Готово!";



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




function categories()
{

	function import_categories($xml)
	{
		if (!isset($xml->Классификатор->Группы))
			return;
		parse_groups($xml->Классификатор->Группы);
	}

	function parse_groups($groups, $parent = 0)
	{
		foreach ($groups->Группа as $group) {

			$id = (string) $group->Ид;
			$name = (string) $group->Наименование;

			// пропускаем удалённые
			if ((string) $group->ПометкаУдаления === 'true') {
				continue;
			}

			$slug = translit($name);

			// ищем по 1С ID
			$existing = get_terms([
				'taxonomy' => 'product_cat',
				'hide_empty' => false,
				'meta_query' => [
					[
						'key' => '1c_id',
						'value' => $id
					]
				]
			]);

			if (!empty($existing)) {
				$term_id = $existing[0]->term_id;
			} else {
				$result = wp_insert_term($name, 'product_cat', [
					'slug' => $slug,
					'parent' => $parent
				]);

				if (is_wp_error($result))
					continue;

				$term_id = $result['term_id'];

				// сохраняем связь с 1С
				update_term_meta($term_id, '1c_id', $id);
			}

			// рекурсия
			if (isset($group->Группы)) {
				parse_groups($group->Группы, $term_id);
			}
		}
	}

	$xml_file = __DIR__ . '/import___86e209bc-0e98-470e-948a-0e59edce081d.xml';
	$xml = simplexml_load_file($xml_file);

	import_categories($xml);
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

			$category_ids = [];

			if (isset($item->Группы)) {
				foreach ($item->Группы->Ид as $group_id) {

					$group_id = (string) $group_id;

					$terms = get_terms([
						'taxonomy' => 'product_cat',
						'hide_empty' => false,
						'meta_query' => [
							[
								'key' => '1c_id',
								'value' => $group_id
							]
						]
					]);

					if (!empty($terms)) {
						$category_ids[] = $terms[0]->term_id;
					}
				}
			}

			if (!empty($category_ids)) {
				wp_set_object_terms($product_id, $category_ids, 'product_cat');
			}
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

	
	
	$att = [];
	
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

		$att_term = [];

		foreach ($offer->ХарактеристикиТовара->ХарактеристикаТовара as $attr) {
			// $name = ((string) $attr->Наименование);
			$name = trim((string) $attr->Наименование, "_ ");
			$value = ((string) $attr->Значение);

			if (!$value)
				continue;

			$taxonomy = 'pa_' . translit($name);

			$term = get_term_by('name', $value, $taxonomy);

			$att[$product_sku][$taxonomy][] = $term->name;
		}
	}

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
			$attrr->set_options(array_unique($att[$product_sku][$taxonomy]));
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
		$parent_product = wc_get_product($product_id);
		$parent_attributes = $parent_product->get_attributes();

		foreach ($offer->ХарактеристикиТовара->ХарактеристикаТовара as $attr) {

			// $name = ((string) $attr->Наименование);
			$name = trim((string) $attr->Наименование, "_ ");
			$value = ((string) $attr->Значение);

			if (!$value)
				continue;

			$taxonomy = 'pa_' . translit($name);

			$term = get_term_by('slug', translit($value), $taxonomy);

			$attributes[$taxonomy] = $term->slug;

			// добавляем термин к родителю, если его нет
			if (!isset($parent_attributes[$taxonomy])) {
				$pa = new WC_Product_Attribute();
				$pa->set_name($taxonomy);
				$pa->set_options([$term->slug]);
				$pa->set_visible(true);
				$pa->set_variation(true);
				$parent_attributes[$taxonomy] = $pa;
			} else {
				$existing_options = $parent_attributes[$taxonomy]->get_options();
				if (!in_array($term->slug, $existing_options)) {
					$existing_options[] = $term->slug;
					$parent_attributes[$taxonomy]->set_options($existing_options);
				}
			}
		}

		$variation->set_attributes($attributes);
		$variation->save();

		// сохраняем обновленные атрибуты родителя
		$parent_product->set_attributes($parent_attributes);
		$parent_product->save();
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
























	function import_categories($xml)
	{
		if (!isset($xml->Классификатор->Группы))
			return;
		parse_groups($xml->Классификатор->Группы);
	}

	function parse_groups($groups, $parent = 0)
	{
		foreach ($groups->Группа as $group) {
			$id = (string) $group->Ид;
			$name = (string) $group->Наименование;

			// пропускаем удалённые
			if ((string) $group->ПометкаУдаления === 'true') {
				continue;
			}

			$slug = translit($name);

			// ищем по 1С ID
			$existing = get_terms([
				'taxonomy' => 'product_cat',
				'hide_empty' => false,
				'meta_query' => [
					[
						'key' => '1c_id',
						'value' => $id
					]
				]
			]);

			if (!empty($existing)) {
				$term_id = $existing[0]->term_id;
			} else {
				$result = wp_insert_term($name, 'product_cat', [
					'slug' => $slug,
					'parent' => $parent
				]);

				if (is_wp_error($result))
					continue;

				$term_id = $result['term_id'];

				// сохраняем связь с 1С
				update_term_meta($term_id, '1c_id', $id);
			}

			// рекурсия
			if (isset($group->Группы)) {
				parse_groups($group->Группы, $term_id);
			}
		}
	}

	$xml = simplexml_load_file('import.xml');

	import_categories($xml);
}

add_action('init', function () {
	if (!isset($_GET['import_1c_full']) || $_GET['import_1c_full'] !== 'norm408')
		return;
	set_time_limit(0);

	// categories();
	// creat_product();
	// creat_attrebutes();
	add_attrebut_on_product();
	// creat_variation();
	// add_terms();
	// add_price();
	// add_image_to_product();

	// ============================================================================================================================
// import_night
// ============================================================================================================================

});
