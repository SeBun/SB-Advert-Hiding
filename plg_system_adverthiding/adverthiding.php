<?php

/**
 * @package     Advert Hiding Plugin to manage advertisement articles access based on hide date
 * @version     1.1.0
 * @Author      Sergey Bunin, https://sebun.ru
 * @copyright   Copyright (C) 2025 Sergey Bunin
 * @license     GNU/GPL 3
 * @since       1.1.0
 */

class PlgSystemAdvertHiding extends JPlugin
{
    /**
     * Запускается после того, как Joomla инициализирует, проверяет и обновляет доступ к статьям.
     */
	public function onAfterInitialise()
    {
		// Получаем текущее время в формате Unix timestamp
        $now = JFactory::getDate()->toUnix();
		
		// Загружаем параметры плагина
        $plugin = JPluginHelper::getPlugin('system', 'adverthiding');
        $params = new JRegistry($plugin->params);
		
        $lastCheck = $params->get('last_check', 0); // Время последней проверки, сохраняется при каждом запуске.
		
		/* TODO: Если несколько пользователей одновременно загрузили страницу, плагин мог запуститься несколько раз почти одновременно. 
				 Это могло привести к тому, что значение last_check не успело обновиться в базе данных для всех процессов, и условие
				 if ($now - $lastCheck > $checkInterval) сработало некорректно. Можно ограничить работу плагина только админкой.
		*/

        // Получаем параметры из настроек
        $checkInterval = (int)$params->get('check_interval', 3600);     // Интервал запуска в секундах. По умолчанию: 1 час
        $batchSize     = (int)$params->get('batch_size', 10);           // Количество обрабатываемых за раз материалов. По умолчанию: 10
		
		
		//JFactory::getApplication()->enqueueMessage('Текст сообщения', 'notice');

        if ($now - $lastCheck > $checkInterval)
        {
            $this->updateAccess($batchSize, $params->get('categories', []));
            
            // Обновляем метку времени
            $params->set('last_check', $now);
			
			// Сохраняем параметры в формате JSON
            $plugin->params = $params->toString('JSON');

            $db = JFactory::getDbo();
            $query = $db->getQuery(true)
				->update($db->quoteName('#__extensions'))
				->set($db->quoteName('params') . ' = ' . $db->quote($plugin->params))
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('element') . ' = ' . $db->quote('adverthiding'))
				->where($db->quoteName('folder') . ' = ' . $db->quote('system'));
                
            $db->setQuery($query);
            try {
                $db->execute();
            } catch (Exception $e) {
				// Логируем ошибку и показываем сообщение
                JLog::add('Ошибка сохранения параметров плагина: ' . $e->getMessage(), JLog::ERROR, 'plg_system_adverthiding');
				JFactory::getApplication()->enqueueMessage('Плагин Advert Hiding: Не удалось обновить параметры.', 'error');
            }
        }
    }

	/**
	 * Обновляет уровень доступа к рекламным статьям в зависимости от даты скрытия.
	 *
	 * @param int   $batchSize  Количество материалов, обрабатываемых в одном запросе
	 * @param array $categories Категории, в которых ведется обработка
	 */
	private function updateAccess(int $batchSize, array $categories = [])
    {
        $db = JFactory::getDbo();
		
        // Получаем динамические ID групп
		$plugin = JPluginHelper::getPlugin('system', 'adverthiding');
		$params = new JRegistry($plugin->params);
		
		$publicGroupId     = (int) $params->get('public_group');     // ID группы уровня Public
		$registeredGroupId = (int) $params->get('registered_group'); // ID группы уровня Registered
		
		// Значения по умолчанию для групп не устанавливаются и должны быть заданы в настройках плагина.
		if (!$publicGroupId || !$registeredGroupId) {
            JLog::add('Не удалось получить ID групп Public или Registered', JLog::ERROR, 'plg_system_adverthiding');
            JFactory::getApplication()->enqueueMessage('Плагин Advert Hiding: Не удалось получить ID групп Public или Registered. Проверьте настройки.', 'error');
			return;
        }

		// Получаем ID полей по их именам
        $advertisementFieldId = $this->getFieldId('advertising');
        $hideDateFieldId = $this->getFieldId('hiding');

        // Значения по умолчанию для полей не устанавливаются и должны быть заданы в настройках плагина.
        if (!$advertisementFieldId || !$hideDateFieldId) {
            JLog::add('Не удалось получить ID полей advertising или hiding', JLog::ERROR, 'plg_system_adverthiding');
            JFactory::getApplication()->enqueueMessage('Плагин Advert Hiding: Не удалось найти поля advertising или hiding. Проверьте наличие пользовательских полей.', 'error');
			return;
        }
		
		// Если в настройках категории заданы некорректно (например, как строка вместо массива), это может привести к ошибкам в запросе.
		if (is_string($categories)) {
			$categories = explode(',', $categories);
		}
		$categories = array_filter(array_map('intval', (array)$categories)); // Удаляем пустые и приводим к int

        // Формируем запрос для получения статей, удовлетворяющих условиям
        $query = $db->getQuery(true)
            ->select('c.id')
            ->from($db->quoteName('#__content', 'c'))
            ->join('INNER', 
                $db->quoteName('#__fields_values', 'f1') . 
                ' ON c.id = f1.item_id AND f1.field_id = ' . (int)$advertisementFieldId . ' AND f1.value = ' . $db->quote('1'))
            ->join('INNER', 
                $db->quoteName('#__fields_values', 'f2') . 
                ' ON c.id = f2.item_id AND f2.field_id = ' . (int)$hideDateFieldId . 
				' AND f2.value IS NOT NULL AND f2.value != \'0000-00-00 00:00:00\' AND f2.value <= ' . $db->quote(JFactory::getDate()->toSql()))
            ->where($db->quoteName('c.access') . ' = ' . (int)$publicGroupId) // Только с уровнем доступа "Public"
            ->where('c.state = 1') // Только опубликованные материалы
            ->order('c.id ASC');

        // Добавляем условие по категориям
        if (!empty($categories)) {
			$query->where('c.catid IN (' . implode(',', array_map('intval', $categories)) . ')');
		}

        // Ограничиваем выборку
        $query->setLimit($batchSize);

        try {
            $db->setQuery($query);
            $articles = $db->loadColumn();
        } catch (Exception $e) {
            //JFactory::getApplication()->enqueueMessage('Ошибка получения статей: ' . $e->getMessage(), 'error');
			JLog::add('Ошибка получения статей: ' . $e->getMessage(), JLog::ERROR, 'plg_system_adverthiding');
            return;
        }

        if ($articles)
        {
            // Обновляем доступ
            $ids = implode(',', array_map('intval', $articles));
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__content'))
                ->set($db->quoteName('access') . ' = ' . (int)$registeredGroupId)
                ->where($db->quoteName('id') . ' IN (' . $ids . ')');
                
            try {
                $db->setQuery($query);
                $db->execute();
            } catch (Exception $e) {
                //JFactory::getApplication()->enqueueMessage('Ошибка обновления доступа к статьям: ' . $e->getMessage(), 'error');
				JLog::add('Ошибка обновления доступа к статьям: ' . $e->getMessage(), JLog::ERROR, 'plg_system_adverthiding');
            }
			
			// Сообщение в админке об успешной операции
			//JFactory::getApplication()->enqueueMessage('SQL Query: ' . $query->dump(), 'notice');
			JFactory::getApplication()->enqueueMessage('Found articles: ' . print_r($articles, true), 'notice');
        }
    }

	/**
     * Извлекает идентификатор поля по его названию.
     *
     * @param string $name имя поля
     * @return int|null ID поля или null если не найдено
     */
    private function getFieldId(string $name): ?int
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__fields'))
            ->where($db->quoteName('name') . ' = ' . $db->quote($name));
            
        try {
            $db->setQuery($query);
            return (int)$db->loadResult();
        } catch (Exception $e) {
            //JFactory::getApplication()->enqueueMessage('Ошибка получения ID поля ' . $name . ': ' . $e->getMessage(), 'error');
			JLog::add('Ошибка получения ID поля ' . $name . ': ' . $e->getMessage(), JLog::ERROR, 'plg_system_adverthiding');
            return null;
        }
    }
	
}

/* Если плагин отработал некорректно, вернуть права можно следующим запросом (только для одной категории с id=36):

UPDATE `gift_content` AS c
JOIN `gift_fields_values` AS fv
  ON c.id = fv.item_id
SET c.access = 1
WHERE c.catid = 36
  AND fv.field_id = (SELECT id FROM gift_fields WHERE name = 'advertising')
  AND fv.value = '1';
  
*/
