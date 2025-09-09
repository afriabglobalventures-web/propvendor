// This file is for page-level components.
import React from 'react';
import { Button, CategoryCard, ListingCard, LocationIcon, CategoryIcon, PriceIcon, UploadIcon, VerifyIcon, PaidIcon } from './components';
import { CATEGORIES, LISTINGS, DEALS } from './constants';

const SearchFilter: React.FC = () => (
  <div className="mt-8 bg-pv-white p-6 rounded-xl shadow-lg w-full max-w-4xl mx-auto">
    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 items-center">
      <div className="relative">
        <LocationIcon className="w-5 h-5 text-pv-text-secondary absolute top-1/2 left-4 -translate-y-1/2" />
        <input type="text" placeholder="Location (e.g., Lagos)" className="w-full pl-12 pr-4 py-3 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-pv-blue" />
      </div>
      <div className="relative">
        <CategoryIcon className="w-5 h-5 text-pv-text-secondary absolute top-1/2 left-4 -translate-y-1/2" />
        <select className="w-full pl-12 pr-4 py-3 border border-gray-200 rounded-lg appearance-none focus:outline-none focus:ring-2 focus:ring-pv-blue bg-white">
          <option>All Categories</option>
          <option>Properties</option>
          <option>Cars</option>
          <option>Rentals</option>
          <option>Services</option>
        </select>
      </div>
      <Button variant="primary" className="w-full h-full text-lg">Search</Button>
    </div>
  </div>
);


export const HomePage: React.FC = () => {
  return (
    <main className="bg-pv-white text-pv-text">
      {/* Hero Section */}
      <section className="bg-pv-light-gray py-20 md:py-28">
        <div className="container mx-auto px-4 sm:px-6 lg:px-8 text-center">
          <h1 className="text-4xl md:text-6xl font-bold text-pv-text max-w-3xl mx-auto">
            Find Your Next Property, Car, or Service in Nigeria
          </h1>
          <p className="mt-4 text-lg md:text-xl text-pv-text-secondary max-w-2xl mx-auto">
            The secure way to buy, sell, and rent with a trusted escrow system.
          </p>
          <SearchFilter />
        </div>
      </section>

      {/* Featured Categories */}
      <section className="py-20">
        <div className="container mx-auto px-4 sm:px-6 lg:px-8">
          <h2 className="text-3xl font-bold text-center mb-12">Explore Categories</h2>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-6 lg:gap-8">
            {CATEGORIES.map(category => (
              <CategoryCard key={category.id} category={category} />
            ))}
          </div>
        </div>
      </section>
      
      {/* Featured Listings */}
      <section className="bg-pv-light-gray py-20">
         <div className="container mx-auto px-4 sm:px-6 lg:px-8">
            <h2 className="text-3xl font-bold text-center mb-12">Featured Listings</h2>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
                {LISTINGS.map(listing => (
                    <ListingCard key={listing.id} listing={listing} />
                ))}
            </div>
         </div>
      </section>

      {/* Booking Deals */}
      <section className="py-20">
        <div className="container mx-auto px-4 sm:px-6 lg:px-8">
          <h2 className="text-3xl font-bold text-center mb-12">Exclusive Booking Deals</h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-8 max-w-4xl mx-auto">
            {DEALS.map(deal => (
              <ListingCard key={deal.id} listing={deal} isDeal={true} />
            ))}
          </div>
        </div>
      </section>

      {/* How It Works */}
      <section className="bg-pv-blue text-white py-20">
        <div className="container mx-auto px-4 sm:px-6 lg:px-8">
          <h2 className="text-3xl font-bold text-center mb-12">How It Works</h2>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-12 text-center">
            <div className="flex flex-col items-center">
                <div className="bg-white/20 p-5 rounded-full mb-4">
                  <UploadIcon className="w-10 h-10 text-white" />
                </div>
                <h3 className="text-xl font-semibold mb-2">1. Post Your Ad</h3>
                <p className="opacity-80">Easily create a listing for your property, vehicle, or service for free.</p>
            </div>
             <div className="flex flex-col items-center">
                <div className="bg-white/20 p-5 rounded-full mb-4">
                  <VerifyIcon className="w-10 h-10 text-white" />
                </div>
                <h3 className="text-xl font-semibold mb-2">2. We Verify & Secure</h3>
                <p className="opacity-80">We verify listings and hold payments in a secure escrow until the deal is done.</p>
            </div>
             <div className="flex flex-col items-center">
                <div className="bg-white/20 p-5 rounded-full mb-4">
                  <PaidIcon className="w-10 h-10 text-white" />
                </div>
                <h3 className="text-xl font-semibold mb-2">3. You Get Paid</h3>
                <p className="opacity-80">Once the buyer confirms, your payment is released to your wallet instantly.</p>
            </div>
          </div>
        </div>
      </section>
    </main>
  );
};
