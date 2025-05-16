<?php

/**
 * @package     Advert Hiding Plugin to manage advertisement articles access based on hide date
 * @version     1.2.1
 * @Author      Sergey Bunin, https://sebun.ru
 * @copyright   Copyright (C) 2025 Sergey Bunin
 * @license     GNU/GPL 3
 * @link        https://sebun.ru
 * @since       1.0.0
 */
 
\defined('_JEXEC') or die;


class PlgSystemAdvertHiding extends JPlugin
{
    /**
     * Запускается после инициализации Joomla, проверяет и обновляет доступ к статьям.
     * @since 1.0.0
     */
	public function onAfterInitialise()
    {
		
	// Ограничиваем выполнение только админкой, если задано в настройках
        if ($this->params->get('admin_only', 0) && !JFactory::getApplication()->isAdmin()) {
		return;
	}

	// Получаем текущее время в формате Unix timestamp
        $now = JFactory::getDate()->toUnix();
	
        $lastCheck = (int) $this->params->get('last_check'); // Время последней проверки, сохраняется при каждом запуске.

	// Получаем параметры из настроек
        $checkInterval = (int) $this->params->get('check_interval', 3600);     // Интервал запуска в секундах. По умолчанию: 1 час
        $batchSize     = (int) $this->params->get('batch_size', 10);           // Количество обрабатываемых за раз материалов. По умолчанию: 10
		

	// Если время запуска подошло, запускаем метод updateAccess
        if ($now - $lastCheck > $checkInterval)
        {
            $this->updateAccess($batchSize, $this->params->get('categories', []));
            
            // Обновляем метку времени
            $this->params->set('last_check', $now);
			
			// Сохраняем параметры в базу данных
			$plugin = JPluginHelper::getPlugin('system', 'adverthiding');
			$db = JFactory::getDbo();
			$query = $db->getQuery(true)
				->update($db->quoteName('#__extensions'))
				->set($db->quoteName('params') . ' = ' . $db->quote($this->params->toString()))
				->where($db->quoteName('extension_id') . ' = ' . (int) $plugin->id);
                
            $db->setQuery($query);
			
            try {
                $db->execute();
				// Сбрасываем внутренний кеш JPluginHelper, чтобы следующие getPlugin() вернули новые params
				JPluginHelper::importPlugin('system','adverthiding', true);
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
	 * @param array $categories Категории, в которых ведется обработка (Id катерий)
	 */
	private function updateAccess(int $batchSize, array $categories = [])
    {
        $db = JFactory::getDbo();
		
        // Получаем динамические ID групп
		$publicGroupId     = (int) $this->params->get('public_group');     // ID группы уровня Public
		$registeredGroupId = (int) $this->params->get('registered_group'); // ID группы уровня Registered
	
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
            ->where('c.state = 1')       // только опубликованные материалы
            ->order('c.publish_up ASC'); // начинаем с самой старой даты публикации

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
			JFactory::getApplication()->enqueueMessage('Обновлен доступ к материалам: ' . implode(",", $articles), 'notice');
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
