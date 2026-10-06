SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `qqbot_com`
--

-- --------------------------------------------------------

--
-- 表的结构 `acessToken`
--

CREATE TABLE `acessToken` (
  `id` int(11) NOT NULL,
  `access_token` varchar(512) NOT NULL,
  `expirationTime` timestamp NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- 转存表中的数据 `acessToken`
--

INSERT INTO `acessToken` (`id`, `access_token`, `expirationTime`) VALUES
(1, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', '2006-06-06 06:06:06');

--
-- 转储表的索引
--

--
-- 表的索引 `acessToken`
--
ALTER TABLE `acessToken`
  ADD UNIQUE KEY `id` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
