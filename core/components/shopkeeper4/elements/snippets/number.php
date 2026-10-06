<?php
return number_format((float)($input ?? $scriptProperties['value'] ?? 0),2,'.',' ');
