<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - {{ __("view-suite::messages.Page Not Found") }}</title>
    <script src="https://cdn.tailwindcss.com/3.4.3"></script>
    <style>
        body {overflow: hidden;}
    </style>
    <style>
        @keyframes float {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-15px);
            }
        }

        @keyframes glitch-1 {
            0% {
                transform: translate(0);
            }
            20% {
                transform: translate(-5px, 5px);
            }
            40% {
                transform: translate(-5px, -5px);
            }
            60% {
                transform: translate(5px, 5px);
            }
            80% {
                transform: translate(5px, -5px);
            }
            100% {
                transform: translate(0);
            }
        }

        @keyframes glitch-2 {
            0% {
                transform: translate(0);
            }
            25% {
                transform: translate(5px, -5px);
            }
            50% {
                transform: translate(-5px, 5px);
            }
            75% {
                transform: translate(5px, 5px);
            }
            100% {
                transform: translate(0);
            }
        }

        .animate-float {
            animation: float 3s ease-in-out infinite;
        }

        .animate-glitch-1 {
            animation: glitch-1 2.5s infinite;
        }

        .animate-glitch-2 {
            animation: glitch-2 3s infinite;
        }
    </style>
</head>
<body>
<section class="relative min-h-screen bg-gray-900 overflow-hidden flex items-center justify-center mb-16 p-6">
    <!-- Background elements -->
    <div class="absolute inset-0 opacity-20">
        <div class="absolute top-20 left-10 w-40 h-40 rounded-full bg-purple-600 blur-3xl"></div>
        <div class="absolute bottom-20 right-10 w-60 h-60 bg-cyan-600 blur-3xl"></div>
    </div>

    <!-- Glitch text container -->
    <div class="relative z-10 text-center max-w-2xl mx-auto">
        <!-- 404 with glitch effect -->
        <div class="relative mb-12">
            <h1 class="text-[120px] md:text-[180px] font-bold text-white tracking-tighter">
        <span class="relative">
          <span class="absolute inset-0 text-purple-400 opacity-70 animate-glitch-1">404</span>
          <span class="absolute inset-0 text-cyan-400 opacity-70 animate-glitch-2">404</span>
          <span class="relative">404</span>
        </span>
            </h1>
        </div>

        <!-- Main heading -->
        <h2 class="text-3xl md:text-5xl font-bold text-white mb-6">
            {{ __("view-suite::messages.Page Not Found") }}
        </h2>

        <!-- Description -->
        <p class="text-gray-400 text-lg mb-10">
            {{__("view-suite::messages.Oops! Looks like you got lost in outer space...")}}
        </p>
        <p class="text-gray-400 text-lg mb-10">
            {{ __("view-suite::messages.The page you are looking for was not found in our galaxy.") }}
        </p>
    </div>

    <!-- Floating elements -->
    <div class="absolute bottom-10 left-1/2 transform -translate-x-1/2 flex space-x-4">
        <div class="w-3 h-3 bg-purple-400 rounded-full animate-float delay-100"></div>
        <div class="w-2 h-2 bg-cyan-400 rounded-full animate-float delay-300"></div>
        <div class="w-4 h-4 bg-white rounded-full animate-float delay-500"></div>
    </div>


</section>
</body>
</html>
