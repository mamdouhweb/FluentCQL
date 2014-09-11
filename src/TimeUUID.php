<?php
namespace FluentCQL;
/**
 * The goods are here: www.ietf.org/rfc/rfc4122.txt.
 */
class TimeUUID {
	const SEM_KEY = 1000;
	const SHM_KEY = 2000;

	const CLOCK_SEQ_KEY = 1;
	const LAST_NANOS_KEY = 2;

	// A grand day! 100's nanoseconds precision at 00:00:00.000 15 Oct 1582.
	protected static $_startEpoch = -122192928000000000;

	protected static $_mac;

	protected static $_clockSeq;

	public static function setMAC($mac)
	{
		self::$_mac = implode('', explode(':', $mac));
	}

	protected static function _unsignedRightShift($number, $amount)
	{
		if ($number >= 0)
			return $number >> $amount;
		$number >>= $amount;
		if ($amount == 1)
			return $number & 0x7fffffffffffffff;
		return $number & ((1 << (64 - $amount)) - 1);
	}

	protected static function _getTimeSafe($sec = null, $msec = 0)
	{
		if (isset($sec)) {
			$nanos = (int)$sec * 10000000 + (int)($msec * 10000000);
		}
		else {
			list($msec, $sec) = explode(' ', microtime());
			$nanos = (int)($sec . substr($msec, 2, 7));
		}

		$nanosSince = $nanos - self::$_startEpoch;

		$semId = sem_get(self::SEM_KEY);
		sem_acquire($semId); //blocking

		$shmId = shm_attach(self::SHM_KEY);
		$lastNanos = shm_get_var($shmId, self::LAST_NANOS_KEY);
		if ($lastNanos === false)
			$lastNanos = 0;

		if ($nanosSince > $lastNanos)
			$lastNanos = $nanosSince;
		else
			$nanosSince = ++$lastNanos;

		shm_put_var($shmId, self::LAST_NANOS_KEY, $lastNanos);

		sem_release($semId);

		return $nanosSince;
	}

	/**
	 * 
	 * @param int $sec
	 * @param double $msec
	 * @return string
	 */
	protected static function _createTimeHex($sec = null, $msec = 0)
	{
		$nanosSince = self::_getTimeSafe($sec, $msec);

		$msb = 0;
		$msb |= (0x00000000ffffffff & $nanosSince) << 32;
		$msb |= self::_unsignedRightShift(0x0000ffff00000000 & $nanosSince, 16);
		$msb |= self::_unsignedRightShift(0xffff000000000000 & $nanosSince, 48);
		$msb |= 0x0000000000001000; // sets the version to 1.

		$timeHex = str_pad(dechex($msb), 16, '0', STR_PAD_LEFT);
		return substr($timeHex, 0, 8) . '-' . substr($timeHex, 8, 4) . '-' . substr($timeHex, 12, 4);
	}

	protected static function _makeClockSeq()
	{
		$clockSeq = 0;
		$clockSeq |= 0x8000; // variant (2 bits)
		$clockSeq |= mt_rand(0, (1 << 14) - 1); // clock sequence (14 bits)

		return dechex($clockSeq);
	}

	public static function getTimeUUID($sec = null, $msec = 0)
	{
		if (self::$_clockSeq === null) {
			$shmId = shm_attach(self::SHM_KEY);
			self::$_clockSeq = shm_get_var($shmId, self::CLOCK_SEQ_KEY);

			if (self::$_clockSeq === false) {
				$semId = sem_get(self::SEM_KEY);
				sem_acquire($semId); //blocking

				if (!shm_has_var($shmId, self::CLOCK_SEQ_KEY)) {
					shm_put_var($shmId, self::CLOCK_SEQ_KEY, self::_makeClockSeq());
				}

				sem_release($semId);

				self::$_clockSeq = shm_get_var($shmId, self::CLOCK_SEQ_KEY);
			}
		}
		return self::_createTimeHex($sec, $msec) . '-' . self::$_clockSeq . '-' . self::$_mac;
	}
}
